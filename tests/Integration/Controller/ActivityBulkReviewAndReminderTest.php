<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\DocumentRevisionRepository;
use App\Repository\EmailNotificationLogRepository;
use App\Service\ActivityDeadlineChecker;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** ActivityController::bulkReview() and ::remindPending(). */
final class ActivityBulkReviewAndReminderTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username, bool $admin = false): Teacher
    {
        $teacher = (new Teacher(new PersonName('Nombre', $username)))->setUsername($username)->setEmail("{$username}@example.com");
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

    private function pendingRevision(Folder $folder, string $name, Teacher $uploader): DocumentRevision
    {
        $document = new Document($folder, $name);
        $file     = new DocumentFile(hash('sha256', $name), $name, 'text/plain', $name . '.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);
        $document->getRevisions()->add($revision);
        $this->persist($document, $file, $revision);

        return $revision;
    }

    private function revisionState(string $id): DocumentRevision
    {
        /** @var DocumentRevisionRepository $revisions */
        $revisions = self::getContainer()->get(DocumentRevisionRepository::class);
        $revision  = $revisions->find($id);
        self::assertNotNull($revision);

        return $revision;
    }

    // ── bulkReview() ─────────────────────────────────────────────────────────

    /** @return array{EducationalCentre, Activity, Teacher, DocumentRevision, DocumentRevision, DocumentRevision} */
    private function reviewScenario(): array
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Entregas');
        $other    = (new Folder())->setDocumentSection($section)->setName('Otra');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $other->addReviewProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Programaciones')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder);
        $reviewer = $this->teacher('revisor');
        $uploader = $this->teacher('subidor');
        $this->persist($centre, $category, $section, $folder, $other, $profile, $activity, $reviewer, $uploader, new SpecificProfileAssignment($profile, null, $reviewer));

        return [
            $centre,
            $activity,
            $reviewer,
            $this->pendingRevision($folder, 'Uno', $uploader),
            $this->pendingRevision($folder, 'Dos', $uploader),
            $this->pendingRevision($other, 'Ajena', $uploader),
        ];
    }

    public function testApprovesTheTickedSubmissionsOfTheActivityAndIgnoresAnyOtherId(): void
    {
        [$centre, $activity, $reviewer, $one, $two, $foreign] = $this->reviewScenario();
        $aid = $activity->getId()->toRfc4122();
        $ids = [$one->getId()->toRfc4122(), $two->getId()->toRfc4122(), $foreign->getId()->toRfc4122()];

        $this->loginAs($reviewer, $centre);
        $this->client->request('GET', '/actividades?category=' . $activity->getCategory()->getId()->toRfc4122() . '&activity=' . $aid);
        self::assertStringContainsString('2 entregas por revisar', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', "/actividades/{$aid}/revisar", [
            '_token'       => $this->csrfToken('activity_bulk_review_' . $aid),
            'decision'     => 'approve',
            'revisions'    => $ids,
            'reviewResult' => 'Correctas',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        foreach ([$ids[0], $ids[1]] as $id) {
            $revision = $this->revisionState($id);
            self::assertFalse($revision->isPendingReview());
            self::assertTrue($revision->isApproved());
            self::assertSame('Correctas', $revision->getReviewResult());
            self::assertSame($id, $revision->getDocument()->getActiveRevision()?->getId()->toRfc4122());
        }
        self::assertTrue($this->revisionState($ids[2])->isPendingReview(), 'a revision from another folder is never touched');

        $this->client->followRedirect();
        self::assertStringContainsString('Se han aprobado 2 entregas.', (string) $this->client->getResponse()->getContent());
    }

    public function testRejectsOnlyTheTickedOnes(): void
    {
        [$centre, $activity, $reviewer, $one, $two] = $this->reviewScenario();
        $aid = $activity->getId()->toRfc4122();

        $this->loginAs($reviewer, $centre);
        $this->client->request('POST', "/actividades/{$aid}/revisar", [
            '_token'    => $this->csrfToken('activity_bulk_review_' . $aid),
            'decision'  => 'reject',
            'revisions' => [$one->getId()->toRfc4122()],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        self::assertTrue($this->revisionState($one->getId()->toRfc4122())->isRejected());
        self::assertTrue($this->revisionState($two->getId()->toRfc4122())->isPendingReview());
    }

    public function testBulkReviewIsDeniedWithoutReviewPermission(): void
    {
        [$centre, $activity, , $one] = $this->reviewScenario();
        $aid      = $activity->getId()->toRfc4122();
        $stranger = $this->teacher('ajeno');
        $this->persist($stranger);

        $this->loginAs($stranger, $centre);
        $this->client->request('POST', "/actividades/{$aid}/revisar", [
            '_token'    => $this->csrfToken('activity_bulk_review_' . $aid),
            'decision'  => 'approve',
            'revisions' => [$one->getId()->toRfc4122()],
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertTrue($this->revisionState($one->getId()->toRfc4122())->isPendingReview());
    }

    // ── remindPending() ──────────────────────────────────────────────────────

    /** @return array{EducationalCentre, Activity, Teacher} a manual activity (no folder): every teacher of the year owes it */
    private function reminderScenario(): array
    {
        self::mockTime('2025-10-10 10:00:00');

        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria final')->setStart(1, 9)->setEnd(30, 6);
        $admin    = $this->teacher('admin', admin: true)->setEmail(null);
        $pending  = $this->teacher('pendiente');
        $done     = $this->teacher('hecho');
        foreach ([$admin, $pending, $done] as $teacher) {
            $teacher->addAcademicYear($year);
        }
        $this->persist(
            $centre, $year, $category, $activity, $admin, $pending, $done,
            (new SettingDefinition())->setKey('notifications.email_notifications_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setTeacherScope(true),
            (new SettingDefinition())->setKey('notifications.email_log_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setCentreScope(true),
        );

        /** @var ActivityDeadlineChecker $deadline */
        $deadline = self::getContainer()->get(ActivityDeadlineChecker::class);
        $this->persist(new ActivityCompletion($activity, $done, null, null, $done, $deadline->currentCycleKey($activity)));

        return [$centre, $activity, $admin];
    }

    /** @return list<string> event key and recipient of every email logged */
    private function loggedEmails(): array
    {
        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);

        return array_map(static fn ($l): string => $l->getEventKey() . ':' . $l->getRecipientName(), $logs->findAll());
    }

    public function testRemindsOnlyWhoStillHasTheActivityPendingAndOnlyOnceAnHour(): void
    {
        [$centre, $activity, $admin] = $this->reminderScenario();
        $aid = $activity->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/actividades/{$aid}/recordar-pendientes", ['_token' => $this->csrfToken('activity_remind_pending_' . $aid)]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        // The admin owes it too but has no address; "hecho" already completed it.
        self::assertSame(['activity_manual_reminder:Nombre pendiente'], $this->loggedEmails());
        $this->client->followRedirect();
        self::assertStringContainsString('Recordatorio enviado a 1 docente.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', "/actividades/{$aid}/recordar-pendientes", ['_token' => $this->csrfToken('activity_remind_pending_' . $aid)]);
        $this->client->followRedirect();
        self::assertStringContainsString('hace menos de una hora', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $this->loggedEmails());
    }

    public function testReminderIsDeniedToAPlainTeacher(): void
    {
        [$centre, $activity] = $this->reminderScenario();
        $aid     = $activity->getId()->toRfc4122();
        $teacher = $this->teacher('docente');
        $this->persist($teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', "/actividades/{$aid}/recordar-pendientes", ['_token' => $this->csrfToken('activity_remind_pending_' . $aid)]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->loggedEmails());
    }
}
