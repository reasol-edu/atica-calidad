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
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class NotificationBellComponentTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testShowsNoBadgeWhenNothingIsPending(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('NotificationBellComponent', [], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('No tienes nada pendiente.', $html);
    }

    public function testListsAPendingActivityDeadline(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Lectura de la política de calidad')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('NotificationBellComponent', [], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Lectura de la política de calidad', $html);
        self::assertSame(1, $component->component()->getTotal());
    }

    /**
     * The bell only ever shows what's personally the teacher's own to review (see
     * PendingReviewFinder's docblock) — the quality-manager role alone isn't enough, so this
     * fixture also assigns the manager a review profile on the folder.
     */
    public function testListsAPendingRevisionForAQualityManagerWhoHoldsTheReviewProfile(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor')->setPosition(0);
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta de calidad')->setPosition(0);
        $folder->addReviewProfile($profile, null);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = new Document($folder, 'Acta de la reunión');
        $file       = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision   = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $manager, $profile, $assignment, $uploader, $section, $folder, $document, $file, $revision);

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('NotificationBellComponent', [], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Acta de la reunión', $html);
        self::assertStringContainsString('Pendiente de revisar', $html);
        self::assertStringContainsString('Entregas pendientes de revisar', $html);

        // Deep-links straight to the section/folder/document, not just the tree's landing page
        // (rendered HTML entity-encodes the query string's '&' as '&amp;').
        $expectedUrl = sprintf(
            '/arbol-documental?section=%s&amp;folder=%s&amp;document=%s',
            $section->getId()->toRfc4122(),
            $folder->getId()->toRfc4122(),
            $document->getId()->toRfc4122(),
        );
        self::assertStringContainsString($expectedUrl, $html);
    }

    public function testBadgeCombinesActivitiesAndPendingReviews(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre   = $this->centre();
        $manager  = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor')->setPosition(0);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9);
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $folder->addReviewProfile($profile, null);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = new Document($folder, 'Acta');
        $file       = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision   = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $manager, $profile, $assignment, $category, $activity, $uploader, $section, $folder, $document, $file, $revision);

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('NotificationBellComponent', [], $this->client);

        self::assertSame(2, $component->component()->getTotal());

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Actividades pendientes de completar', $html);
        self::assertStringContainsString('Entregas pendientes de revisar', $html);
    }

    /**
     * The bell must never widen to "everything an admin/quality manager is entitled to review" —
     * that broader view is the dashboard's separate, admin-only "Todas las revisiones pendientes"
     * section (DashboardAllPendingReviewComponent), never the bell.
     */
    public function testDoesNotListAPendingRevisionForAQualityManagerWithNoReviewProfileOfTheirOwn(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta de calidad')->setPosition(0);
        $document = new Document($folder, 'Acta de la reunión');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $manager, $uploader, $section, $folder, $document, $file, $revision);

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('NotificationBellComponent', [], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('Acta de la reunión', $html);
        self::assertSame(0, $component->component()->getTotal());
    }
}
