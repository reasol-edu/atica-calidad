<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

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
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class DashboardPendingReviewComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testRendersNothingForATeacherWithNoReviewAccess(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardPendingReviewComponent', ['centre' => $centre], $this->client);

        $bodyHtml = trim((string) $component->render()->crawler()->filter('body')->html());
        self::assertSame('', $bodyHtml);
    }

    /**
     * Being a quality manager alone is no longer enough — this widget is scoped to what the
     * teacher personally holds a review profile for (see PendingReviewFinder's docblock), so the
     * fixture needs an actual profile assignment, not just the manager role.
     */
    /** @return array{profile: SpecificProfile, section: DocumentSection, folder: Folder, assignment: SpecificProfileAssignment} */
    private function reviewerFixture(EducationalCentre $centre, Teacher $reviewer): array
    {
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor')->setPosition(0);
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta de calidad')->setPosition(0);
        $folder->addReviewProfile($profile, null);
        $assignment = new SpecificProfileAssignment($profile, null, $reviewer);

        return ['profile' => $profile, 'section' => $section, 'folder' => $folder, 'assignment' => $assignment];
    }

    public function testRendersNothingForAQualityManagerWithNoReviewProfileOfTheirOwn(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $this->persist($centre, $manager);

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('DashboardPendingReviewComponent', ['centre' => $centre], $this->client);

        $bodyHtml = trim((string) $component->render()->crawler()->filter('body')->html());
        self::assertSame('', $bodyHtml);
    }

    public function testShowsThePositiveEmptyStateForAReviewerWithNothingPending(): void
    {
        $centre    = $this->centre();
        $reviewer  = $this->teacher('revisor');
        $fixture   = $this->reviewerFixture($centre, $reviewer);
        $this->persist($centre, $reviewer, $fixture['profile'], $fixture['section'], $fixture['folder'], $fixture['assignment']);

        $this->loginAs($reviewer, $centre);
        $component = $this->createLiveComponent('DashboardPendingReviewComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('¡Todo revisado!', $html);
    }

    public function testListsAPendingRevision(): void
    {
        $centre   = $this->centre();
        $reviewer = $this->teacher('revisor');
        $fixture  = $this->reviewerFixture($centre, $reviewer);
        $uploader = $this->teacher('subidor');
        $document = new Document($fixture['folder'], 'Acta de la reunión');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $reviewer, $fixture['profile'], $fixture['section'], $fixture['folder'], $fixture['assignment'], $uploader, $document, $file, $revision);

        $this->loginAs($reviewer, $centre);
        $component = $this->createLiveComponent('DashboardPendingReviewComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Acta de la reunión', $html);
        self::assertStringContainsString('Carpeta de calidad', $html);
    }
}
