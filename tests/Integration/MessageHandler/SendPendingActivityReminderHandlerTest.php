<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Message\SendPendingActivityReminderMessage;
use App\MessageHandler\SendPendingActivityReminderHandler;
use App\Repository\ActivityRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\EmailNotificationLogRepository;
use App\Repository\TeacherRepository;
use App\Service\ActivityCompletionChecker;
use App\Service\ActivityDeadlineChecker;
use App\Service\AppSettingsInterface;
use App\Service\NonWorkingDayChecker;
use App\Service\NotificationMailer;
use App\Service\PendingActivityReminderFinder;
use App\Tests\Integration\RepositoryTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SendPendingActivityReminderHandlerTest extends RepositoryTestCase
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

    private function booleanDefinition(string $key, string $default): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::Boolean)->setDefaultValue($default)->setTeacherScope(true);
    }

    private function integerDefinition(string $key, string $default): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::Integer)->setDefaultValue($default)->setTeacherScope(true)->setMinValue(0)->setMaxValue(365);
    }

    private function handler(MailerInterface $mailer): SendPendingActivityReminderHandler
    {
        $finder = new PendingActivityReminderFinder(
            self::getContainer()->get(ActivityRepository::class),
            self::getContainer()->get(ActivityCompletionChecker::class),
            new ActivityDeadlineChecker(self::getContainer()->get('clock')),
        );

        $notificationMailer = new NotificationMailer(
            $mailer,
            self::getContainer()->get(AppSettingsInterface::class),
            new NullLogger(),
            $this->em,
            'no-reply@example.com',
            'ÁTICA Calidad',
        );

        return new SendPendingActivityReminderHandler(
            self::getContainer()->get(EducationalCentreRepository::class),
            self::getContainer()->get(TeacherRepository::class),
            self::getContainer()->get(AppSettingsInterface::class),
            $finder,
            $notificationMailer,
            self::getContainer()->get(NonWorkingDayChecker::class),
            self::getContainer()->get('clock'),
            self::getContainer()->get(TranslatorInterface::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(Environment::class),
        );
    }

    private function logs(): EmailNotificationLogRepository
    {
        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);

        return $logs;
    }

    public function testSendsAReminderWithAPendingActivity(): void
    {
        self::mockTime('2025-09-29 10:00:00'); // a Monday, 1 day before the Sep 30 deadline

        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $teacher->addAcademicYear($year);

        $enabled = $this->booleanDefinition('notifications.pending_activity_reminder_enabled', 'true');
        $emailOn = $this->booleanDefinition('notifications.email_notifications_enabled', 'true');
        $logOn   = (new SettingDefinition())->setKey('notifications.email_log_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setCentreScope(true);
        $warning = $this->integerDefinition('notifications.pending_activity_reminder_warning_days', '5');
        $this->persist($centre, $year, $category, $activity, $teacher, $enabled, $emailOn, $logOn, $warning);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->handler($mailer)(new SendPendingActivityReminderMessage());

        $this->em->clear();
        $logEntries = $this->logs()->findAll();
        self::assertCount(1, $logEntries);
        self::assertSame('pending_activity_reminder', $logEntries[0]->getEventKey());
        self::assertTrue($logEntries[0]->isSuccess());
    }

    public function testDoesNotSendWhenTheTeacherHasDisabledTheReminder(): void
    {
        self::mockTime('2025-09-29 10:00:00'); // a Monday, 1 day before the Sep 30 deadline

        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $teacher->addAcademicYear($year);

        $disabled = $this->booleanDefinition('notifications.pending_activity_reminder_enabled', 'false');
        $warning  = $this->integerDefinition('notifications.pending_activity_reminder_warning_days', '5');
        $this->persist($centre, $year, $category, $activity, $teacher, $disabled, $warning);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($mailer)(new SendPendingActivityReminderMessage());

        self::assertSame([], $this->logs()->findAll());
    }

    public function testDoesNotSendWhenNothingIsDueOrOverdue(): void
    {
        self::mockTime('2025-09-10 10:00:00'); // 20 days before the Sep 30 deadline, outside the 5-day window

        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $teacher->addAcademicYear($year);

        $enabled = $this->booleanDefinition('notifications.pending_activity_reminder_enabled', 'true');
        $warning = $this->integerDefinition('notifications.pending_activity_reminder_warning_days', '5');
        $this->persist($centre, $year, $category, $activity, $teacher, $enabled, $warning);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($mailer)(new SendPendingActivityReminderMessage());
    }

    public function testDoesNotSendOnAWeekend(): void
    {
        self::mockTime('2025-09-27 10:00:00'); // a Saturday, 3 days before the Sep 30 deadline

        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $teacher->addAcademicYear($year);

        $enabled = $this->booleanDefinition('notifications.pending_activity_reminder_enabled', 'true');
        $warning = $this->integerDefinition('notifications.pending_activity_reminder_warning_days', '5');
        $this->persist($centre, $year, $category, $activity, $teacher, $enabled, $warning);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($mailer)(new SendPendingActivityReminderMessage());
    }

    public function testDoesNotSendOnADeclaredNonWorkingDay(): void
    {
        self::mockTime('2025-09-29 10:00:00'); // a Monday, 1 day before the Sep 30 deadline

        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $holiday  = (new NonWorkingDay())->setAcademicYear($year)->setDate(new \DateTimeImmutable('2025-09-29'));
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $teacher->addAcademicYear($year);

        $enabled = $this->booleanDefinition('notifications.pending_activity_reminder_enabled', 'true');
        $warning = $this->integerDefinition('notifications.pending_activity_reminder_warning_days', '5');
        $this->persist($centre, $year, $holiday, $category, $activity, $teacher, $enabled, $warning);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($mailer)(new SendPendingActivityReminderMessage());
    }
}
