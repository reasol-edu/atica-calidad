<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentReviewNotificationKind;
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
use App\Repository\DocumentRepository;
use App\Repository\DocumentReviewNotificationEventRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Service\AppSettingsInterface;
use App\Service\DocumentReviewOutcomeNotifier;
use App\Service\NotificationMailer;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DocumentReviewOutcomeNotifierTest extends RepositoryTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username)->setEmail("{$username}@example.com");
    }

    private function modeDefinition(string $key, string $default): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::Choice)->setDefaultValue($default)
            ->setChoices('disabled,individual,daily_digest')->setTeacherScope(true);
    }

    private function notifier(MailerInterface $mailer): DocumentReviewOutcomeNotifier
    {
        $notificationMailer = new NotificationMailer(
            $mailer,
            self::getContainer()->get(AppSettingsInterface::class),
            new NullLogger(),
            $this->em,
            'no-reply@example.com',
            'ÁTICA Calidad',
        );

        return new DocumentReviewOutcomeNotifier(
            self::getContainer()->get(SpecificProfileAssignmentRepository::class),
            self::getContainer()->get(AppSettingsInterface::class),
            $notificationMailer,
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(TranslatorInterface::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(Environment::class),
        );
    }

    private function events(): DocumentReviewNotificationEventRepository
    {
        /** @var DocumentReviewNotificationEventRepository $events */
        $events = self::getContainer()->get(DocumentReviewNotificationEventRepository::class);

        return $events;
    }

    /** @return array{document: Document, file: DocumentFile, revision: DocumentRevision} */
    private function revisionFixture(Folder $folder, Teacher $uploader): array
    {
        $document = new Document($folder, 'Acta');
        $file     = new DocumentFile(hash('sha256', uniqid('', true)), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);

        return ['document' => $document, 'file' => $file, 'revision' => $revision];
    }

    public function testNotifiesAnUploadProfileHolderInIndividualMode(): void
    {
        $centre    = $this->centre();
        $section   = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder    = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subida');
        $folder->addUploadProfile($profile);
        $holder    = $this->teacher('titular');
        $assign    = new SpecificProfileAssignment($profile, null, $holder);
        $uploader  = $this->teacher('subidor');
        $mode      = $this->modeDefinition('notifications.document_accepted_notification_mode', 'individual');
        $emailOn   = (new SettingDefinition())->setKey('notifications.email_notifications_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setTeacherScope(true);
        $fixture   = $this->revisionFixture($folder, $uploader);

        $this->persist($centre, $section, $folder, $profile, $holder, $assign, $uploader, $mode, $emailOn, $fixture['document'], $fixture['file'], $fixture['revision']);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->notifier($mailer)->notifyOutcome($fixture['revision'], DocumentReviewNotificationKind::Approved);
    }

    public function testOnlyNotifiesTheUploaderWhenTheActivityScopeIsIndividual(): void
    {
        $centre    = $this->centre();
        $section   = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder    = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subida');
        $folder->addUploadProfile($profile);
        $category  = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity  = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6)
            ->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual);
        $otherHolder = $this->teacher('otro-titular');
        $assign      = new SpecificProfileAssignment($profile, null, $otherHolder);
        $uploader    = $this->teacher('subidor');
        $mode        = $this->modeDefinition('notifications.document_rejected_notification_mode', 'individual');
        $emailOn     = (new SettingDefinition())->setKey('notifications.email_notifications_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setTeacherScope(true);
        $fixture     = $this->revisionFixture($folder, $uploader);

        $this->persist($centre, $section, $folder, $profile, $category, $activity, $otherHolder, $assign, $uploader, $mode, $emailOn, $fixture['document'], $fixture['file'], $fixture['revision']);
        $documentId = $fixture['document']->getId()->toRfc4122();
        $revisionId = $fixture['revision']->getId()->toRfc4122();

        // Folder::$activity is the inverse side of a OneToOne whose owning side (Activity::$folder)
        // was just set above — Doctrine only hydrates that inverse reference from the database, so
        // it stays null on the in-memory object graph until reloaded, exactly like a fresh request
        // would see it.
        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $document  = $documents->findById($documentId);
        self::assertNotNull($document);
        /** @var DocumentRevisionRepository $revisions */
        $revisions = self::getContainer()->get(DocumentRevisionRepository::class);
        $revision  = $revisions->findByIdAndDocument($revisionId, $document);
        self::assertNotNull($revision);

        $mailer = $this->createMock(MailerInterface::class);
        // Only the uploader's own address should ever be dialled — the other profile holder must
        // not be, since in Individual scope this submission belongs to the uploader alone.
        $mailer->expects(self::once())->method('send')->with(self::callback(
            static fn (Email $email): bool => $email->getTo()[0]->getAddress() === 'subidor@example.com',
        ));

        $this->notifier($mailer)->notifyOutcome($revision, DocumentReviewNotificationKind::Rejected);
    }

    public function testDoesNotNotifyARecipientWhoHasDisabledTheSetting(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subida');
        $folder->addUploadProfile($profile);
        $holder   = $this->teacher('titular');
        $assign   = new SpecificProfileAssignment($profile, null, $holder);
        $uploader = $this->teacher('subidor');
        $disabled = $this->modeDefinition('notifications.document_accepted_notification_mode', 'disabled');
        $fixture  = $this->revisionFixture($folder, $uploader);

        $this->persist($centre, $section, $folder, $profile, $holder, $assign, $uploader, $disabled, $fixture['document'], $fixture['file'], $fixture['revision']);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->notifier($mailer)->notifyOutcome($fixture['revision'], DocumentReviewNotificationKind::Approved);

        self::assertCount(0, $this->events()->findByCentre($centre));
    }

    public function testQueuesADigestEventInsteadOfSendingWhenInDailyDigestMode(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subida');
        $folder->addUploadProfile($profile);
        $holder   = $this->teacher('titular');
        $assign   = new SpecificProfileAssignment($profile, null, $holder);
        $uploader = $this->teacher('subidor');
        $mode     = $this->modeDefinition('notifications.document_rejected_notification_mode', 'daily_digest');
        $fixture  = $this->revisionFixture($folder, $uploader);

        $this->persist($centre, $section, $folder, $profile, $holder, $assign, $uploader, $mode, $fixture['document'], $fixture['file'], $fixture['revision']);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->notifier($mailer)->notifyOutcome($fixture['revision'], DocumentReviewNotificationKind::Rejected);

        $events = $this->events()->findByCentre($centre);
        self::assertCount(1, $events);
        self::assertSame(DocumentReviewNotificationKind::Rejected, $events[0]->getKind());
    }

    public function testDoesNothingWhenTheFolderHasNoUploadProfile(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $uploader = $this->teacher('subidor');
        $fixture  = $this->revisionFixture($folder, $uploader);

        $this->persist($centre, $section, $folder, $uploader, $fixture['document'], $fixture['file'], $fixture['revision']);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->notifier($mailer)->notifyOutcome($fixture['revision'], DocumentReviewNotificationKind::Approved);
    }
}
