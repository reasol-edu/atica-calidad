<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentReviewNotificationEvent;
use App\Entity\DocumentReviewNotificationKind;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Message\SendDocumentReviewDigestMessage;
use App\MessageHandler\SendDocumentReviewDigestHandler;
use App\Repository\DocumentReviewNotificationEventRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\FolderRepository;
use App\Repository\TeacherRepository;
use App\Service\AppSettingsInterface;
use App\Service\DocumentReviewNotifier;
use App\Service\DocumentReviewOutcomeNotifier;
use App\Service\DocumentTreeAccessChecker;
use App\Service\NonWorkingDayChecker;
use App\Service\NotificationMailer;
use App\Service\PendingReviewFinder;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SendDocumentReviewDigestHandlerTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

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

    /** @return list<SettingDefinition> the 3 mode settings, all defaulting to $default, plus email-notifications-enabled */
    private function allModesDefaultingTo(string $default): array
    {
        return [
            $this->modeDefinition('notifications.pending_review_notification_mode', $default),
            $this->modeDefinition('notifications.document_accepted_notification_mode', $default),
            $this->modeDefinition('notifications.document_rejected_notification_mode', $default),
            (new SettingDefinition())->setKey('notifications.email_notifications_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setTeacherScope(true),
        ];
    }

    private function handler(MailerInterface $mailer): SendDocumentReviewDigestHandler
    {
        $notificationMailer = new NotificationMailer(
            $mailer,
            self::getContainer()->get(AppSettingsInterface::class),
            new NullLogger(),
            $this->em,
            'no-reply@example.com',
            'ÁTICA Calidad',
        );

        return new SendDocumentReviewDigestHandler(
            self::getContainer()->get(EducationalCentreRepository::class),
            self::getContainer()->get(TeacherRepository::class),
            self::getContainer()->get(AppSettingsInterface::class),
            self::getContainer()->get(DocumentReviewNotificationEventRepository::class),
            new PendingReviewFinder(
                self::getContainer()->get(DocumentRevisionRepository::class),
                self::getContainer()->get(DocumentTreeAccessChecker::class),
                self::getContainer()->get(FolderRepository::class),
            ),
            self::getContainer()->get(DocumentReviewNotifier::class),
            self::getContainer()->get(DocumentReviewOutcomeNotifier::class),
            $notificationMailer,
            self::getContainer()->get(NonWorkingDayChecker::class),
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get('clock'),
            self::getContainer()->get(TranslatorInterface::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(Environment::class),
        );
    }

    /** @return array{document: Document, file: DocumentFile, revision: DocumentRevision} */
    private function revisionFixture(Folder $folder, Teacher $uploader, bool $pendingReview = false): array
    {
        $document = new Document($folder, 'Acta');
        $file     = new DocumentFile(hash('sha256', uniqid('', true)), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, $pendingReview, $uploader);

        return ['document' => $document, 'file' => $file, 'revision' => $revision];
    }

    public function testDoesNotSendWhenTheQueueIsEmptyAndNothingIsPending(): void
    {
        self::mockTime('2025-09-29 10:00:00'); // a Monday

        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $teacher  = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $this->persist($centre, $year, $teacher, ...$this->allModesDefaultingTo('daily_digest'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($mailer)(new SendDocumentReviewDigestMessage());
    }

    public function testSendsACombinedDigestAndClearsTheQueueAfterwards(): void
    {
        self::mockTime('2025-09-29 10:00:00'); // a Monday

        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);

        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $reviewProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $uploadProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subida');
        $folder->addReviewProfile($reviewProfile);
        $folder->addUploadProfile($uploadProfile);

        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $reviewAssign = new SpecificProfileAssignment($reviewProfile, null, $teacher);
        $uploadAssign = new SpecificProfileAssignment($uploadProfile, null, $teacher);

        $uploader = $this->teacher('subidor');

        $pending  = $this->revisionFixture($folder, $uploader, pendingReview: true);
        $approved = $this->revisionFixture($folder, $uploader);
        $rejected = $this->revisionFixture($folder, $uploader);

        $this->persist(
            $centre, $year, $section, $folder, $reviewProfile, $uploadProfile,
            $teacher, $reviewAssign, $uploadAssign, $uploader,
            $pending['document'], $pending['file'], $pending['revision'],
            $approved['document'], $approved['file'], $approved['revision'],
            $rejected['document'], $rejected['file'], $rejected['revision'],
            new DocumentReviewNotificationEvent($pending['revision'], DocumentReviewNotificationKind::PendingReview),
            new DocumentReviewNotificationEvent($approved['revision'], DocumentReviewNotificationKind::Approved),
            new DocumentReviewNotificationEvent($rejected['revision'], DocumentReviewNotificationKind::Rejected),
            ...$this->allModesDefaultingTo('daily_digest'),
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->handler($mailer)(new SendDocumentReviewDigestMessage());

        /** @var DocumentReviewNotificationEventRepository $events */
        $events = self::getContainer()->get(DocumentReviewNotificationEventRepository::class);
        self::assertCount(0, $events->findByCentre($centre), 'the queue must be emptied once the digest for every teacher has been built');
    }

    public function testDoesNotSendTheSectionsWhoseModeIsNotDailyDigest(): void
    {
        self::mockTime('2025-09-29 10:00:00'); // a Monday

        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);

        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $uploadProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subida');
        $folder->addUploadProfile($uploadProfile);

        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $uploadAssign = new SpecificProfileAssignment($uploadProfile, null, $teacher);
        $uploader     = $this->teacher('subidor');

        $accepted = $this->revisionFixture($folder, $uploader);

        // pending_review stays 'individual' — a queued PendingReview event should never occur in
        // that mode anyway (see DocumentReviewNotifier), and accepted/rejected default to disabled
        // except accepted, which is explicitly set to daily_digest below.
        $pendingMode  = $this->modeDefinition('notifications.pending_review_notification_mode', 'individual');
        $acceptedMode = $this->modeDefinition('notifications.document_accepted_notification_mode', 'daily_digest');
        $rejectedMode = $this->modeDefinition('notifications.document_rejected_notification_mode', 'disabled');
        $emailOn      = (new SettingDefinition())->setKey('notifications.email_notifications_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setTeacherScope(true);

        $this->persist(
            $centre, $year, $section, $folder, $uploadProfile, $teacher, $uploadAssign, $uploader,
            $accepted['document'], $accepted['file'], $accepted['revision'],
            new DocumentReviewNotificationEvent($accepted['revision'], DocumentReviewNotificationKind::Approved),
            $pendingMode, $acceptedMode, $rejectedMode, $emailOn,
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->handler($mailer)(new SendDocumentReviewDigestMessage());
    }

    public function testDoesNotSendOnAWeekend(): void
    {
        self::mockTime('2025-09-27 10:00:00'); // a Saturday

        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);

        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $uploadProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subida');
        $folder->addUploadProfile($uploadProfile);
        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $uploadAssign = new SpecificProfileAssignment($uploadProfile, null, $teacher);
        $uploader     = $this->teacher('subidor');
        $accepted     = $this->revisionFixture($folder, $uploader);

        $this->persist(
            $centre, $year, $section, $folder, $uploadProfile, $teacher, $uploadAssign, $uploader,
            $accepted['document'], $accepted['file'], $accepted['revision'],
            new DocumentReviewNotificationEvent($accepted['revision'], DocumentReviewNotificationKind::Approved),
            ...$this->allModesDefaultingTo('daily_digest'),
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($mailer)(new SendDocumentReviewDigestMessage());

        /** @var DocumentReviewNotificationEventRepository $events */
        $events = self::getContainer()->get(DocumentReviewNotificationEventRepository::class);
        self::assertCount(1, $events->findByCentre($centre), 'a skipped centre must keep its queue untouched for the next run');
    }

    public function testDoesNotSendOnADeclaredNonWorkingDay(): void
    {
        self::mockTime('2025-09-29 10:00:00'); // a Monday

        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $holiday = (new NonWorkingDay())->setAcademicYear($year)->setDate(new \DateTimeImmutable('2025-09-29'));

        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $uploadProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subida');
        $folder->addUploadProfile($uploadProfile);
        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $uploadAssign = new SpecificProfileAssignment($uploadProfile, null, $teacher);
        $uploader     = $this->teacher('subidor');
        $accepted     = $this->revisionFixture($folder, $uploader);

        $this->persist(
            $centre, $year, $holiday, $section, $folder, $uploadProfile, $teacher, $uploadAssign, $uploader,
            $accepted['document'], $accepted['file'], $accepted['revision'],
            new DocumentReviewNotificationEvent($accepted['revision'], DocumentReviewNotificationKind::Approved),
            ...$this->allModesDefaultingTo('daily_digest'),
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($mailer)(new SendDocumentReviewDigestMessage());
    }
}
