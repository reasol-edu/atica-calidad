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

    public function testListsAPendingRevisionForAQualityManager(): void
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
        self::assertStringContainsString('Acta de la reunión', $html);
        self::assertStringContainsString('Pendiente de revisar', $html);

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
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9);
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $document = new Document($folder, 'Acta');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $manager, $category, $activity, $uploader, $section, $folder, $document, $file, $revision);

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('NotificationBellComponent', [], $this->client);

        self::assertSame(2, $component->component()->getTotal());
    }
}
