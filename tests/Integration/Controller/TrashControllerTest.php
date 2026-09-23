<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

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
use App\Repository\ActivityRepository;
use App\Repository\DocumentFileRepository;
use App\Repository\DocumentRepository;
use App\Repository\FolderRepository;
use App\Service\TrashService;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class TrashControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username, bool $admin = false): Teacher
    {
        $teacher = (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
        $teacher->setAdmin($admin);

        return $teacher;
    }

    /** See FolderControllerTest for why this push/save dance is needed between KernelBrowser requests. */
    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function trash(): TrashService
    {
        /** @var TrashService $trash */
        $trash = self::getContainer()->get(TrashService::class);

        return $trash;
    }

    private function documents(): DocumentRepository
    {
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);

        return $documents;
    }

    private function activities(): ActivityRepository
    {
        /** @var ActivityRepository $activities */
        $activities = self::getContainer()->get(ActivityRepository::class);

        return $activities;
    }

    /** @return array{EducationalCentre, Folder, Document, Teacher} a document with one revision, in the trash */
    private function trashedDocument(string $content = 'contenido'): array
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $admin    = $this->teacher('root', admin: true);
        $document = new Document($folder, 'Plan de centro');
        $file     = new DocumentFile(hash('sha256', $content), $content, 'text/plain', 'plan.txt', \strlen($content));
        $revision = new DocumentRevision($document, 1, $file, false, $admin);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($centre, $section, $folder, $admin, $document, $file, $revision);

        $this->trash()->trashDocument($document, $admin);

        return [$centre, $folder, $document, $admin];
    }

    public function testATrashedDocumentIsHiddenEverywhereButTheTrash(): void
    {
        [$centre, $folder, $document, $admin] = $this->trashedDocument();
        $this->em->clear();

        self::assertSame([], $this->documents()->findByFolder($folder));
        self::assertNull($this->documents()->findById($document->getId()->toRfc4122()));

        $this->loginAs($admin, $centre);
        $crawler = $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/papelera');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $row = $crawler->filter('[data-trash-item="' . $document->getId()->toRfc4122() . '"]');
        self::assertStringContainsString('Plan de centro', $row->text());
        self::assertStringContainsString('Sección › Carpeta · 1 versión', $row->text());
        self::assertStringContainsString('Eliminado por root, Nombre', $row->text());
    }

    public function testRestoresADocument(): void
    {
        [$centre, $folder, $document, $admin] = $this->trashedDocument();
        $id = $document->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . "/papelera/documentos/{$id}/recuperar", ['_token' => $this->csrfToken('trash_document_' . $id)]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        $restored = $this->documents()->findById($id);
        self::assertNotNull($restored);
        self::assertFalse($restored->isTrashed());
        self::assertNotNull($restored->getActiveRevision(), 'back with its revisions');
    }

    public function testPurgesADocumentAndItsNoLongerUsedFiles(): void
    {
        [$centre, , $document, $admin] = $this->trashedDocument('solo aquí');
        $id     = $document->getId()->toRfc4122();
        $fileId = $document->getActiveRevision()?->getFile()->getId()->toRfc4122();
        self::assertNotNull($fileId);

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . "/papelera/documentos/{$id}/eliminar", ['_token' => $this->csrfToken('trash_document_' . $id)]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        self::assertNull($this->documents()->findTrashedByIdAndCentre($id, $centre));
        /** @var DocumentFileRepository $files */
        $files = self::getContainer()->get(DocumentFileRepository::class);
        self::assertNull($files->findById($fileId));
    }

    public function testTheTrashIsOnlyForResponsibilityManagers(): void
    {
        [$centre, , $document] = $this->trashedDocument();
        $teacher = $this->teacher('docente');
        $this->persist($teacher);
        $id = $document->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/papelera');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . "/papelera/documentos/{$id}/recuperar", ['_token' => $this->csrfToken('trash_document_' . $id)]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testATrashedDocumentOfAnotherCentreIsNotFound(): void
    {
        [, , $document] = $this->trashedDocument();
        $other = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Ciudad');
        $admin = $this->teacher('otro', admin: true);
        $this->persist($other, $admin);
        $id = $document->getId()->toRfc4122();

        $this->loginAs($admin, $other);
        $this->client->request('POST', '/centro/' . $other->getId()->toRfc4122() . "/papelera/documentos/{$id}/recuperar", ['_token' => $this->csrfToken('trash_document_' . $id)]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ── Activities ───────────────────────────────────────────────────────────

    /** @return array{EducationalCentre, ActivityCategory, Folder, Activity, Teacher} */
    private function trashedActivityWithFolder(?EducationalCentre $centre = null, ?Teacher $admin = null): array
    {
        $centre ??= $this->centre();
        $admin  ??= $this->teacher('root', admin: true);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Actividades');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Entregas');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder)->setAutoComplete(true);
        $this->persist($centre, $category, $section, $folder, $activity, $admin);

        $this->trash()->trashActivity($activity, $admin);

        return [$centre, $category, $folder, $activity, $admin];
    }

    public function testATrashedActivityFreesItsFolderAndGetsItBackOnRestore(): void
    {
        [$centre, , $folder, $activity, $admin] = $this->trashedActivityWithFolder();
        $id = $activity->getId()->toRfc4122();
        $this->em->clear();

        /** @var FolderRepository $folders */
        $folders = self::getContainer()->get(FolderRepository::class);
        self::assertNull($folders->findById($folder->getId()->toRfc4122())?->getActivity(), 'the folder no longer backs it');

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . "/papelera/actividades/{$id}/recuperar", ['_token' => $this->csrfToken('trash_activity_' . $id)]);
        $this->client->followRedirect();
        self::assertStringContainsString('Recuperado de la papelera.', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $restored = $this->activities()->findById($id);
        self::assertNotNull($restored);
        self::assertSame($folder->getId()->toRfc4122(), $restored->getFolder()?->getId()->toRfc4122());
        self::assertTrue($restored->isAutoComplete(), 'as it was, settings included');
    }

    public function testARestoredActivityComesBackWithoutAFolderAnotherOneTookMeanwhile(): void
    {
        [$centre, $category, $folder, $activity, $admin] = $this->trashedActivityWithFolder();
        $id = $activity->getId()->toRfc4122();
        $this->persist((new Activity())->setCategory($category)->setTitle('Otra')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder));

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/centro/' . $centre->getId()->toRfc4122() . "/papelera/actividades/{$id}/recuperar", ['_token' => $this->csrfToken('trash_activity_' . $id)]);
        $this->client->followRedirect();
        self::assertStringContainsString('sin carpeta de entregas', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $restored = $this->activities()->findById($id);
        self::assertNotNull($restored);
        self::assertNull($restored->getFolder());
    }

    public function testExpiredItemsArePurgedAfterTheRetentionPeriod(): void
    {
        self::mockTime('2026-09-01 10:00:00');
        [$centre, , $old, $admin] = $this->trashedDocument('viejo');
        $oldId = $old->getId()->toRfc4122();

        self::mockTime('2026-09-20 10:00:00');
        [, , , $activity] = $this->trashedActivityWithFolder($centre, $admin);

        self::mockTime('2026-10-05 10:00:00'); // 34 days after the first, 15 after the second
        $purged = $this->trash()->purgeExpired();

        self::assertSame(1, $purged['documents']);
        self::assertSame(0, $purged['activities']);
        self::assertSame(1, $purged['files']);
        $this->em->clear();
        self::assertSame([], $this->documents()->findTrashedBefore(new \DateTimeImmutable('2030-01-01')));
        self::assertNull($this->documents()->findTrashedByIdAndCentre($oldId, $centre));
        self::assertNotNull($this->activities()->findTrashedByIdAndCentre($activity->getId()->toRfc4122(), $centre));
    }
}
