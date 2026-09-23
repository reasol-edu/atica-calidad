<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\FolderRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** Read acknowledgement from the screens: the document tree, the dashboard card and the report. */
final class ReadAcknowledgementUiTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private EducationalCentre $centre;
    private DocumentSection $section;
    private Folder $folder;
    private Teacher $manager;
    private Teacher $reader;
    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year         = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($year);
        $this->manager = (new Teacher(new PersonName('Laura', 'Calidad')))->setUsername('calidad');
        $this->reader  = (new Teacher(new PersonName('Luis', 'Lector')))->setUsername('lector');
        $this->manager->addAcademicYear($year);
        $this->reader->addAcademicYear($year);
        $this->centre->addQualityManager($this->manager);
        $this->section = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->folder  = (new Folder())->setDocumentSection($this->section)->setName('Políticas');

        $this->document = new Document($this->folder, 'Política de calidad');
        $file           = new DocumentFile(hash('sha256', 'politica'), 'x', 'text/plain', 'politica.txt', 1);
        $revision       = new DocumentRevision($this->document, 1, $file, false, $this->manager);
        $this->document->getRevisions()->add($revision);
        $this->document->setActiveRevision($revision);

        $this->persist($this->centre, $year, $this->manager, $this->reader, $this->section, $this->folder, $this->document, $file, $revision);
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return ['centre' => $this->centre, 'initialSectionId' => $this->section->getId()->toRfc4122()];
    }

    public function testTheManagerTurnsItOnAndTheReaderConfirms(): void
    {
        $folderId   = $this->folder->getId()->toRfc4122();
        $documentId = $this->document->getId()->toRfc4122();

        $this->loginAs($this->manager, $this->centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->props(), $this->client);
        $component->call('toggleFolderSettings', ['id' => $folderId]);
        self::assertStringContainsString('Requiere acuse de lectura', $component->render()->toString());
        $component->call('toggleReadAcknowledgement', ['id' => $folderId]);

        $this->em->clear();
        /** @var FolderRepository $folders */
        $folders = self::getContainer()->get(FolderRepository::class);
        self::assertTrue($folders->findById($folderId)?->requiresReadAcknowledgement());

        // The reader: "Por leer", then "Leído el …" once confirmed.
        $this->loginAs($this->reader, $this->centre);
        $this->client->request('GET', '/');
        self::assertStringContainsString('Documentos por leer', (string) $this->client->getResponse()->getContent());

        $component = $this->createLiveComponent('SectionBrowserComponent', $this->props(), $this->client);
        $component->call('toggleFolder', ['id' => $folderId]);
        $html = $component->render()->toString();
        self::assertStringContainsString('Por leer', $html);
        self::assertStringContainsString('Confirmar lectura', $html);

        $component->call('acknowledgeDocument', ['folderId' => $folderId, 'id' => $documentId]);
        $html = $component->render()->toString();
        self::assertStringContainsString('Leído el', $html);
        self::assertStringNotContainsString('Confirmar lectura', $html);

        $this->client->request('GET', '/');
        self::assertStringNotContainsString('Documentos por leer', (string) $this->client->getResponse()->getContent());

        // The manager: "Leído 1/1" (the reader; the manager uploaded it), and who.
        $this->loginAs($this->manager, $this->centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->props(), $this->client);
        $component->call('toggleFolder', ['id' => $folderId]);
        self::assertStringContainsString('Leído 1/1', $component->render()->toString());
        $component->call('toggleReadStatus', ['id' => $documentId]);
        self::assertStringContainsString('Lector, Luis', $component->render()->toString());
    }

    public function testAReaderCannotAcknowledgeWhereNothingIsRequired(): void
    {
        $this->loginAs($this->reader, $this->centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->props(), $this->client);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $component->call('acknowledgeDocument', ['folderId' => $this->folder->getId()->toRfc4122(), 'id' => $this->document->getId()->toRfc4122()]);
    }

    public function testTheReport(): void
    {
        $folder = self::getContainer()->get(FolderRepository::class)->findById($this->folder->getId()->toRfc4122());
        $folder?->setRequiresReadAcknowledgement(true);
        $this->em->flush();
        $centreId = $this->centre->getId()->toRfc4122();

        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', "/centro/{$centreId}/informes");
        self::assertStringContainsString('1 documento con acuse de lectura.', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', "/centro/{$centreId}/informes/acuses-de-lectura.pdf");
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->request('GET', "/centro/{$centreId}/informes/acuses-de-lectura.xlsx");
        self::assertSame(200, $this->client->getInternalResponse()->getStatusCode());
        self::assertStringContainsString('acuses-de-lectura-', (string) $this->client->getInternalResponse()->getHeader('Content-Disposition'));
    }
}
