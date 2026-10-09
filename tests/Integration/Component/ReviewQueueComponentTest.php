<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Model\ReviewQueueItem;
use App\Service\ReviewQueueBuilder;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ReviewQueueComponentTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    /**
     * A centre with a reviewer who holds the review profile of two folders: a plain one and an activity's.
     *
     * @return array{0: EducationalCentre, 1: Teacher, 2: array<string, DocumentRevision>}
     */
    private function scenario(): array
    {
        self::mockTime('2026-10-05 10:00:00');
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $reviewer = (new Teacher(new PersonName('Rev', 'revisor')))->setUsername('revisor');
        $uploader = (new Teacher(new PersonName('Sub', 'subidor')))->setUsername('subidor');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisión');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $plain    = (new Folder())->setDocumentSection($section)->setName('Procedimientos');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Memorias');
        $plain->addReviewProfile($profile);
        $folder->addReviewProfile($profile);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Seguimiento');
        // Due 2026-10-08: three days away, so its submissions are "critical".
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(8, 10)->setFolder($folder);
        $this->persist($centre, $reviewer, $uploader, $profile, $section, $plain, $folder, $category, $activity, new SpecificProfileAssignment($profile, null, $reviewer));

        $file      = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $this->persist($file);
        $revisions = [];
        // Oldest first as created: an old plain document, then an activity submission and a recent plain one.
        foreach ([['Antiguo', $plain, '2026-09-01 09:00:00'], ['Entrega', $folder, '2026-10-04 09:00:00'], ['Reciente', $plain, '2026-10-05 09:00:00']] as [$name, $in, $when]) {
            self::mockTime($when);
            $document = new Document($in, $name);
            $revision = new DocumentRevision($document, 1, $file, true, $uploader);
            $document->getRevisions()->add($revision);
            $this->persist($document, $revision);
            $revisions[$name] = $revision;
        }
        self::mockTime('2026-10-05 10:00:00');

        return [$centre, $reviewer, $revisions];
    }

    public function testTheQueueIsOrderedByUrgencyThenAge(): void
    {
        [$centre, $reviewer] = $this->scenario();

        /** @var ReviewQueueBuilder $builder */
        $builder = self::getContainer()->get(ReviewQueueBuilder::class);
        $items   = $builder->build($reviewer, $centre);

        self::assertSame(
            ['Entrega:critical', 'Antiguo:waiting', 'Reciente:normal'],
            array_map(static fn (ReviewQueueItem $i): string => $i->revision->getDocument()->getName() . ':' . $i->urgency, $items),
        );
        self::assertSame(3, $items[0]->daysToDeadline);
    }

    public function testApprovingInOneClickActivatesTheRevision(): void
    {
        [$centre, $reviewer, $revisions] = $this->scenario();
        $this->loginAs($reviewer, $centre);

        $component = $this->createLiveComponent('ReviewQueueComponent', ['centre' => $centre], $this->client);
        $component->call('approve', ['id' => $revisions['Antiguo']->getId()->toRfc4122()]);

        $this->em->clear();
        $reloaded = $this->em->find(DocumentRevision::class, $revisions['Antiguo']->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isPendingReview());
        self::assertSame($reloaded, $reloaded->getDocument()->getActiveRevision());
    }

    public function testRejectingNeedsAReasonAndRecordsIt(): void
    {
        [$centre, $reviewer, $revisions] = $this->scenario();
        $this->loginAs($reviewer, $centre);
        $id = $revisions['Reciente']->getId()->toRfc4122();

        $component = $this->createLiveComponent('ReviewQueueComponent', ['centre' => $centre], $this->client);
        $component->call('startReject', ['id' => $id]);
        $component->set('rejectReason', '');
        $component->call('confirmReject');

        $this->em->clear();
        self::assertTrue($this->em->find(DocumentRevision::class, $revisions['Reciente']->getId())?->isPendingReview(), 'no reason, no rejection');

        $component->call('useReason', ['key' => 'incomplete']);
        $component->call('confirmReject');

        $this->em->clear();
        $reloaded = $this->em->find(DocumentRevision::class, $revisions['Reciente']->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isPendingReview());
        self::assertNull($reloaded->getDocument()->getActiveRevision());
        self::assertStringContainsString('incompleto', (string) $reloaded->getReviewResult());
    }

    public function testSomeoneWhoDoesNotReviewTheFolderCannotApproveIt(): void
    {
        [$centre, , $revisions] = $this->scenario();
        $stranger = (new Teacher(new PersonName('Aje', 'ajeno')))->setUsername('ajeno');
        $this->persist($stranger);
        $this->loginAs($stranger, $centre);

        $component = $this->createLiveComponent('ReviewQueueComponent', ['centre' => $centre], $this->client);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $component->call('approve', ['id' => $revisions['Antiguo']->getId()->toRfc4122()]);
    }
}
