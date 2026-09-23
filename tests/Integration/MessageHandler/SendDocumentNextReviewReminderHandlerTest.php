<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\Document;
use App\Entity\DocumentFile;
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
use App\Message\SendDocumentNextReviewReminderMessage;
use App\MessageHandler\SendDocumentNextReviewReminderHandler;
use App\Repository\EducationalCentreRepository;
use App\Repository\EmailNotificationLogRepository;
use App\Repository\TeacherRepository;
use App\Service\AppSettingsInterface;
use App\Service\DocumentNextReviewReminderFinder;
use App\Service\DocumentReviewSchedule;
use App\Service\NonWorkingDayChecker;
use App\Service\NotificationMailer;
use App\Tests\Integration\RepositoryTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SendDocumentNextReviewReminderHandlerTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    /** 2025-10-13 is a Monday. */
    private const string MONDAY = '2025-10-13 07:30:00';

    private EducationalCentre $centre;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->persist(
            $this->centre,
            $this->year,
            (new SettingDefinition())->setKey('notifications.email_notifications_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setTeacherScope(true),
            (new SettingDefinition())->setKey('notifications.email_log_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setCentreScope(true),
        );
    }

    private function teacher(string $username, bool $inYear = true): Teacher
    {
        $teacher = (new Teacher(new PersonName('Nombre', $username)))->setUsername($username)->setEmail("{$username}@example.com");
        if ($inYear) {
            $this->year->addTeacher($teacher);
        }
        $this->persist($teacher);

        return $teacher;
    }

    /** A folder whose responsible profile is held by $responsible (none when null). */
    private function folder(?Teacher $responsible): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Procedimientos');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Vigentes');
        $this->persist($section, $folder);
        if ($responsible !== null) {
            $profile = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Coordinación');
            $folder->addResponsibleProfile($profile);
            $this->persist($profile, new SpecificProfileAssignment($profile, null, $responsible));
        }

        return $folder;
    }

    private function document(Folder $folder, string $name, string $nextReview, Teacher $uploader): void
    {
        $document = (new Document($folder, $name))->setNextReviewAt(new \DateTimeImmutable($nextReview));
        $file     = new DocumentFile(hash('sha256', $name), 'x', 'application/pdf', 'x.pdf', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($file, $document, $revision);
    }

    /** @param list<Email> $sent */
    private function runReminder(array &$sent): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (Email $email) use (&$sent): void {
            $sent[] = $email;
        });

        /** @var DocumentNextReviewReminderFinder $finder */
        $finder = self::getContainer()->get(DocumentNextReviewReminderFinder::class);
        /** @var DocumentReviewSchedule $schedule */
        $schedule = self::getContainer()->get(DocumentReviewSchedule::class);

        (new SendDocumentNextReviewReminderHandler(
            self::getContainer()->get(EducationalCentreRepository::class),
            self::getContainer()->get(TeacherRepository::class),
            self::getContainer()->get(AppSettingsInterface::class),
            $finder,
            $schedule,
            new NotificationMailer($mailer, self::getContainer()->get(AppSettingsInterface::class), new NullLogger(), $this->em, 'no-reply@example.com', 'ÁTICA Calidad'),
            self::getContainer()->get(NonWorkingDayChecker::class),
            self::getContainer()->get('clock'),
            self::getContainer()->get(TranslatorInterface::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(Environment::class),
        ))(new SendDocumentNextReviewReminderMessage());
    }

    private function recipientsOf(array $sent): array
    {
        return array_map(static fn (Email $e): string => $e->getTo()[0]->getAddress(), $sent);
    }

    public function testTheResponsibleGetsOverdueAndUpcomingReviewsOnTheFirstWorkingDayOfTheWeek(): void
    {
        self::mockTime(self::MONDAY);
        $responsible = $this->teacher('coordinadora');
        $folder      = $this->folder($responsible);
        $this->document($folder, 'PR-01 Control documental', '2025-10-01', $responsible); // overdue
        $this->document($folder, 'PR-02 Compras', '2025-11-05', $responsible);            // within 30 days
        $this->document($folder, 'PR-03 Formación', '2026-03-01', $responsible);          // far off

        $sent = [];
        $this->runReminder($sent);

        self::assertSame(['coordinadora@example.com'], $this->recipientsOf($sent));
        self::assertInstanceOf(\Symfony\Bridge\Twig\Mime\TemplatedEmail::class, $sent[0]);
        $html = (string) ($sent[0]->getContext()['bodyHtml'] ?? '');
        self::assertStringContainsString('PR-01 Control documental', $html);
        self::assertStringContainsString('Revisión vencida desde el 01/10/2025', $html);
        self::assertStringContainsString('PR-02 Compras', $html);
        self::assertStringNotContainsString('PR-03 Formación', $html);
        self::assertSame('2 documentos pendientes de revisar', $sent[0]->getSubject());

        $this->em->clear();
        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);
        self::assertSame(SendDocumentNextReviewReminderHandler::EVENT_KEY, $logs->findAll()[0]->getEventKey());
    }

    /** Weekly, not daily: once Monday has gone out, Tuesday sends nothing. */
    public function testNothingIsSentLaterInTheWeek(): void
    {
        self::mockTime('2025-10-14 07:30:00');
        $responsible = $this->teacher('coordinadora');
        $this->document($this->folder($responsible), 'PR-01', '2025-10-01', $responsible);

        $sent = [];
        $this->runReminder($sent);

        self::assertSame([], $sent);
    }

    /** A Monday holiday moves the weekly reminder to Tuesday. */
    public function testAMondayHolidayMovesItToTuesday(): void
    {
        self::mockTime('2025-10-14 07:30:00');
        $this->persist((new NonWorkingDay())->setAcademicYear($this->year)->setDate(new \DateTimeImmutable('2025-10-13')));
        $responsible = $this->teacher('coordinadora');
        $this->document($this->folder($responsible), 'PR-01', '2025-10-01', $responsible);

        $sent = [];
        $this->runReminder($sent);

        self::assertSame(['coordinadora@example.com'], $this->recipientsOf($sent));
    }

    public function testAFolderWithoutResponsiblesRemindsTheQualityManagersOnly(): void
    {
        self::mockTime(self::MONDAY);
        $quality = $this->teacher('calidad', inYear: false);
        $plain   = $this->teacher('docente');
        $this->centre->getQualityManagers()->add($quality);
        $this->em->flush();
        $this->document($this->folder(null), 'Plan de centro', '2025-10-20', $plain);

        $sent = [];
        $this->runReminder($sent);

        self::assertSame(['calidad@example.com'], $this->recipientsOf($sent), 'even a quality manager who teaches no group that year');
    }

    public function testNoOneElseIsReminded(): void
    {
        self::mockTime(self::MONDAY);
        $responsible = $this->teacher('coordinadora');
        $this->teacher('docente');
        $this->document($this->folder($responsible), 'PR-01', '2025-10-01', $responsible);

        $sent = [];
        $this->runReminder($sent);

        self::assertNotContains('docente@example.com', $this->recipientsOf($sent));
    }

    public function testTheReminderCanBeTurnedOff(): void
    {
        self::mockTime(self::MONDAY);
        $this->persist((new SettingDefinition())->setKey(SendDocumentNextReviewReminderHandler::ENABLED_SETTING)->setType(SettingType::Boolean)->setDefaultValue('false')->setTeacherScope(true));
        $responsible = $this->teacher('coordinadora');
        $this->document($this->folder($responsible), 'PR-01', '2025-10-01', $responsible);

        $sent = [];
        $this->runReminder($sent);

        self::assertSame([], $sent);
    }
}
