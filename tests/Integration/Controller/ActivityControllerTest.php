<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivitySubmissionScope;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ActivityControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function category(EducationalCentre $centre): ActivityCategory
    {
        return (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
    }

    private function activity(ActivityCategory $category): Activity
    {
        return (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function admin(string $username = 'admin'): Teacher
    {
        $teacher = $this->teacher($username);
        $teacher->setAdmin(true);

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

    private function uploadedFile(string $content, string $originalName = 'archivo.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'activity_controller_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $originalName, 'application/pdf', null, true);
    }

    // ── index() tab gating ───────────────────────────────────────────────────

    public function testEditTabIsForcedBackToViewWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/actividades?tab=edit');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Pestañas de Actividades', $body, 'a teacher without RESPONSIBILITIES must never see the tab bar at all');
    }

    public function testEditTabIsAvailableToAnAdmin(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/actividades?tab=edit');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Pestañas de Actividades', $body);
        self::assertStringContainsString('Categorías', $body, 'the edit tab must mount the category tree component');
    }

    // ── uploadSubmissions() ──────────────────────────────────────────────────

    public function testUploadSubmissionsOnAnActivityWithoutAFolderIs404(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", [
            '_token' => $this->csrfToken('activity_submission_upload_' . $activityId),
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadSubmissionsCreatesADocumentForAByProfileSlot(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity   = $this->activity($category)->setFolder($folder);
        $teacher    = $this->teacher('secretario');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assignment);
        $activityId = $activity->getId()->toRfc4122();

        // Compute the slot key the same way the server will: profileId:::  (no listItem/nameListItem/teacher segments).
        $slotKey = $profile->getId()->toRfc4122() . ':::';

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", [
            '_token' => $this->csrfToken('activity_submission_upload_' . $activityId),
            'items'  => [0 => ['slotKey' => $slotKey]],
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $docs      = $documents->findByFolder($folder);
        self::assertCount(1, $docs);
        self::assertSame('Secretario/a', $docs[0]->getName());
        $activeRevision = $docs[0]->getActiveRevision();
        self::assertNotNull($activeRevision);
        self::assertSame('secretario', $activeRevision->getUploadedBy()->getUsername());
    }

    public function testUploadSubmissionsRejectedWithInvalidCsrfToken(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $activity   = $this->activity($category)->setFolder($folder);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assignment);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", [
            '_token' => 'invalido',
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadSubmissionsWithNoFilesFlashesAnErrorWithoutCreatingAnything(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $activity = $this->activity($category)->setFolder($folder);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $activity, $teacher);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", [
            '_token' => $this->csrfToken('activity_submission_upload_' . $activityId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        self::assertCount(0, $documents->findByFolder($folder));
    }

    public function testUploadSubmissionsSkipsASlotTheTeacherIsNotAllowedToFill(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);
        // $stranger holds NO assignment to $profile at all.
        $stranger = $this->teacher('ajeno');

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $stranger);
        $activityId = $activity->getId()->toRfc4122();
        $slotKey    = $profile->getId()->toRfc4122() . ':::';

        $this->loginAs($stranger, $centre);
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", [
            '_token' => $this->csrfToken('activity_submission_upload_' . $activityId),
            'items'  => [0 => ['slotKey' => $slotKey]],
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        self::assertCount(0, $documents->findByFolder($folder), 'created=0 because the only submitted file matched a slot the uploader may not fill');
    }

    public function testUploadSubmissionsSkipsAnUnknownSlotKey(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $activity = $this->activity($category)->setFolder($folder);
        $admin    = $this->admin();
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $activity, $admin);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", [
            '_token' => $this->csrfToken('activity_submission_upload_' . $activityId),
            'items'  => [0 => ['slotKey' => 'clave-inventada-que-no-existe']],
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        self::assertCount(0, $documents->findByFolder($folder));
    }

    /**
     * A file large enough to trip either size guard (the Content-Length/post_max_size check or the
     * per-file MAX_SUBMISSION_SIZE one — whichever fires first under this environment's php.ini)
     * must be rejected without creating anything.
     */
    public function testUploadSubmissionsRejectsAnOversizedFileAndCreatesNothing(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $activity   = $this->activity($category)->setFolder($folder);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assignment);
        $activityId = $activity->getId()->toRfc4122();
        $slotKey    = $profile->getId()->toRfc4122() . ':::';

        $path = tempnam(sys_get_temp_dir(), 'activity_controller_test_');
        self::assertNotFalse($path);
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        fseek($handle, 20 * 1024 * 1024, SEEK_SET);
        fwrite($handle, 'x');
        fclose($handle);
        $oversized = new UploadedFile($path, 'grande.pdf', 'application/pdf', null, true);

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", [
            '_token' => $this->csrfToken('activity_submission_upload_' . $activityId),
            'items'  => [0 => ['slotKey' => $slotKey]],
        ], [
            'files' => [0 => $oversized],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        self::assertCount(0, $documents->findByFolder($folder));
    }

    /**
     * Regression test for the index-desync bug: browsers (mobile Safari included) can omit an
     * untouched <input type="file"> entirely from the multipart body, so files[]/items[] MUST use
     * the SAME explicit index rather than relying on PHP's implicit renumbering of files[] — a
     * request presenting only index 1 (index 0 missing entirely, as if the first dropzone's input
     * was never touched) must still land in the row items[1] names, not be silently misattributed
     * to items[0].
     */
    public function testUploadSubmissionsHandlesAGapInFileIndicesWithoutDesync(): void
    {
        $centre = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profileA = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil A');
        $profileB = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil B');
        $folder->addUploadProfile($profileA);
        $folder->addUploadProfile($profileB);
        $activity = $this->activity($category)->setFolder($folder);
        $admin    = $this->admin();

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profileA, $profileB, $activity, $admin);
        $activityId = $activity->getId()->toRfc4122();
        $slotKeyB   = $profileB->getId()->toRfc4122() . ':::';

        $this->loginAs($admin, $centre);
        // Only index 1 is present in the request — as a browser that skipped an untouched
        // index-0 dropzone would send — and its slotKey names profile B, not A.
        $this->client->request(
            'POST',
            "/actividades/{$activityId}/entregas/subir",
            [
                '_token' => $this->csrfToken('activity_submission_upload_' . $activityId),
                'items'  => [1 => ['slotKey' => $slotKeyB]],
            ],
            ['files' => [1 => $this->uploadedFile('contenido')]],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $docs      = $documents->findByFolder($folder);
        self::assertCount(1, $docs, 'the file at index 1 must be attributed to profile B (its own items[1] row), not silently dropped or misfiled');
        self::assertSame('Perfil B', $docs[0]->getName());
    }

    /**
     * Regression test for the Individual-scope misattribution bug: when a folder MANAGER uploads on
     * behalf of a specific teacher's Individual-scope slot, the created document must record that
     * teacher as the uploader ($slot->teacher), not the manager who physically submitted it — or
     * resolveSlot() would never again recognise the slot as filled from that teacher's own
     * perspective.
     */
    public function testUploadSubmissionsAttributesAnIndividualSlotToItsOwnTeacherNotTheManager(): void
    {
        $centre = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $responsibleProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefe/a de Estudios');
        $folder->addResponsibleProfile($responsibleProfile);
        $tutorProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $folder->addUploadProfile($tutorProfile);

        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual);

        $manager           = $this->teacher('jefatura_estudios');
        $managerAssignment = new SpecificProfileAssignment($responsibleProfile, null, $manager);
        $tutor             = $this->teacher('tutor');
        $tutorAssignment   = new SpecificProfileAssignment($tutorProfile, null, $tutor);

        $this->persist(
            $centre, $category, $folder->getDocumentSection(), $folder,
            $responsibleProfile, $tutorProfile, $activity,
            $manager, $managerAssignment, $tutor, $tutorAssignment,
        );
        $activityId = $activity->getId()->toRfc4122();
        $slotKey    = $tutorProfile->getId()->toRfc4122() . ':::' . $tutor->getId()->toRfc4122();

        // The MANAGER submits on the tutor's behalf.
        $this->loginAs($manager, $centre);
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", [
            '_token' => $this->csrfToken('activity_submission_upload_' . $activityId),
            'items'  => [0 => ['slotKey' => $slotKey]],
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $docs      = $documents->findByFolder($folder);
        self::assertCount(1, $docs);
        $activeRevision = $docs[0]->getActiveRevision();
        self::assertNotNull($activeRevision);
        self::assertSame('tutor', $activeRevision->getUploadedBy()->getUsername(), 'the submission must be recorded under the tutor, not the manager who physically uploaded it');
    }

    /** A slot resolveSlot() already finds a document for must never receive a second, duplicate document. */
    public function testUploadSubmissionsSkipsASlotThatAlreadyHasADocument(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $activity   = $this->activity($category)->setFolder($folder);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assignment);
        $activityId = $activity->getId()->toRfc4122();
        $slotKey    = $profile->getId()->toRfc4122() . ':::';

        $this->loginAs($teacher, $centre);
        $requestPayload = static fn () => [
            'items' => [0 => ['slotKey' => $slotKey]],
        ];

        // First upload fills the slot.
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", array_merge(
            ['_token' => $this->csrfToken('activity_submission_upload_' . $activityId)],
            $requestPayload(),
        ), ['files' => [0 => $this->uploadedFile('primero')]]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        // A second attempt at the SAME slot must be ignored, not create a duplicate document.
        $this->client->request('POST', "/actividades/{$activityId}/entregas/subir", array_merge(
            ['_token' => $this->csrfToken('activity_submission_upload_' . $activityId)],
            $requestPayload(),
        ), ['files' => [0 => $this->uploadedFile('segundo')]]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        self::assertCount(1, $documents->findByFolder($folder), 'a duplicate submission to an already-filled slot must never create a second document');
    }
}
