<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\EmailNotificationLogRepository;
use App\Service\AppSettingsInterface;
use App\Service\NotificationMailer;
use App\Tests\Integration\RepositoryTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

final class NotificationMailerTest extends RepositoryTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(): Teacher
    {
        return (new Teacher(new PersonName('Ana', 'García')))->setUsername('agarcia')->setEmail('ana@example.com');
    }

    private function booleanDefinition(string $key, string $default): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::Boolean)->setDefaultValue($default);
    }

    private function logs(): EmailNotificationLogRepository
    {
        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);

        return $logs;
    }

    private function notifier(MailerInterface $mailer, LoggerInterface $logger): NotificationMailer
    {
        return new NotificationMailer(
            $mailer,
            self::getContainer()->get(AppSettingsInterface::class),
            $logger,
            $this->em,
            'no-reply@example.com',
            'ÁTICA Calidad',
        );
    }

    public function testDoesNotSendWhenTheRecipientHasDisabledEmailNotifications(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher();
        $def     = $this->booleanDefinition('notifications.email_notifications_enabled', 'false');
        $this->persist($centre, $teacher, $def);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->notifier($mailer, $this->createStub(LoggerInterface::class))
            ->send($teacher, $centre, 'activity_reminder', 'Asunto', 'Título', '<p>Cuerpo</p>');

        self::assertCount(0, $this->logs()->findAll());
    }

    public function testSendsAndLogsWhenBothSettingsAreEnabled(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher();
        $enabled = $this->booleanDefinition('notifications.email_notifications_enabled', 'true');
        $logged  = $this->booleanDefinition('notifications.email_log_enabled', 'true');
        $this->persist($centre, $teacher, $enabled, $logged);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->notifier($mailer, $this->createStub(LoggerInterface::class))
            ->send($teacher, $centre, 'activity_reminder', 'Asunto', 'Título', '<p>Cuerpo</p>');

        $this->em->clear();
        $logEntries = $this->logs()->findAll();
        self::assertCount(1, $logEntries);
        self::assertSame('activity_reminder', $logEntries[0]->getEventKey());
        self::assertSame('Asunto', $logEntries[0]->getSubject());
        self::assertTrue($logEntries[0]->isSuccess());
    }

    public function testDoesNotLogWhenTheCentreHasLoggingDisabled(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher();
        $enabled    = $this->booleanDefinition('notifications.email_notifications_enabled', 'true');
        $notLogged  = $this->booleanDefinition('notifications.email_log_enabled', 'false');
        $this->persist($centre, $teacher, $enabled, $notLogged);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->notifier($mailer, $this->createStub(LoggerInterface::class))
            ->send($teacher, $centre, 'activity_reminder', 'Asunto', 'Título', '<p>Cuerpo</p>');

        self::assertCount(0, $this->logs()->findAll());
    }

    public function testLogsAFailedSendWithTheTransportErrorMessage(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher();
        $enabled = $this->booleanDefinition('notifications.email_notifications_enabled', 'true');
        $logged  = $this->booleanDefinition('notifications.email_log_enabled', 'true');
        $this->persist($centre, $teacher, $enabled, $logged);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('SMTP caído'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->notifier($mailer, $logger)
            ->send($teacher, $centre, 'activity_reminder', 'Asunto', 'Título', '<p>Cuerpo</p>');

        $this->em->clear();
        $logEntries = $this->logs()->findAll();
        self::assertCount(1, $logEntries);
        self::assertFalse($logEntries[0]->isSuccess());
        self::assertSame('SMTP caído', $logEntries[0]->getErrorMessage());
    }
}
