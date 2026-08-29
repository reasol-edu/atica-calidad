<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Message\PurgeEmailNotificationLogMessage;
use App\MessageHandler\PurgeEmailNotificationLogHandler;
use App\Repository\EmailNotificationLogRepository;
use App\Service\AppSettingsInterface;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PurgeEmailNotificationLogHandlerTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function log(EducationalCentre $centre, string $sentAt): EmailNotificationLog
    {
        return new EmailNotificationLog($centre, null, 'Ana García', 'password_reset', 'Asunto', true, null, new \DateTimeImmutable($sentAt));
    }

    private function retentionDefinition(string $default): SettingDefinition
    {
        return (new SettingDefinition())->setKey('notifications.log_retention_days')->setType(SettingType::Integer)->setDefaultValue($default);
    }

    private function logs(): EmailNotificationLogRepository
    {
        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);

        return $logs;
    }

    public function testPurgesLogsOlderThanTheConfiguredRetentionWindow(): void
    {
        self::mockTime('2025-09-20 12:00:00');

        $centre = $this->centre();
        $def    = $this->retentionDefinition('30');
        $old    = $this->log($centre, '2025-08-01 10:00:00');
        $recent = $this->log($centre, '2025-09-15 10:00:00');
        $this->persist($centre, $def, $old, $recent);

        $handler = new PurgeEmailNotificationLogHandler(
            $this->logs(),
            self::getContainer()->get(AppSettingsInterface::class),
            self::getContainer()->get('clock'),
        );
        $handler(new PurgeEmailNotificationLogMessage());

        $this->em->clear();
        $remaining = $this->logs()->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('2025-09-15 10:00:00', $remaining[0]->getSentAt()->format('Y-m-d H:i:s'));
    }

    public function testDoesNothingWhenNoRetentionIsConfigured(): void
    {
        $centre = $this->centre();
        $old    = $this->log($centre, '2020-01-01 10:00:00');
        $this->persist($centre, $old);

        $handler = new PurgeEmailNotificationLogHandler(
            $this->logs(),
            self::getContainer()->get(AppSettingsInterface::class),
            self::getContainer()->get('clock'),
        );
        $handler(new PurgeEmailNotificationLogMessage());

        $this->em->clear();
        self::assertCount(1, $this->logs()->findAll());
    }

    public function testDoesNothingWhenRetentionIsZeroOrNegative(): void
    {
        $centre = $this->centre();
        $def    = $this->retentionDefinition('0');
        $old    = $this->log($centre, '2020-01-01 10:00:00');
        $this->persist($centre, $def, $old);

        $handler = new PurgeEmailNotificationLogHandler(
            $this->logs(),
            self::getContainer()->get(AppSettingsInterface::class),
            self::getContainer()->get('clock'),
        );
        $handler(new PurgeEmailNotificationLogMessage());

        $this->em->clear();
        self::assertCount(1, $this->logs()->findAll());
    }
}
