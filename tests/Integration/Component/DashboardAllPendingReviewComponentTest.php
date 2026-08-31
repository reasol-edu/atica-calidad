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
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class DashboardAllPendingReviewComponentTest extends ControllerTestCase
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

    public function testRendersNothingForAPlainTeacher(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardAllPendingReviewComponent', ['centre' => $centre], $this->client);

        $bodyHtml = trim((string) $component->render()->crawler()->filter('body')->html());
        self::assertSame('', $bodyHtml);
    }

    /**
     * The whole point of this widget: a quality manager sees a pending revision here even though
     * they hold no review profile of their own on that folder (compare
     * DashboardPendingReviewComponentTest, where the same fixture renders nothing).
     */
    public function testListsAPendingRevisionForAQualityManagerWithNoReviewProfileOfTheirOwn(): void
    {
        $centre   = $this->centre();
        $manager  = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta de calidad')->setPosition(0);
        $document = new Document($folder, 'Acta de la reunión');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $manager, $uploader, $section, $folder, $document, $file, $revision);

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('DashboardAllPendingReviewComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Acta de la reunión', $html);
        self::assertStringContainsString('Todas las revisiones pendientes', $html);
    }

    public function testShowsThePositiveEmptyStateForAnAdminWithNothingPending(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('DashboardAllPendingReviewComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('¡Todo revisado!', $html);
    }
}
