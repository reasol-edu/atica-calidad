<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\ActivityLog;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Message\PurgeActivityLogMessage;
use App\MessageHandler\PurgeActivityLogHandler;
use App\Repository\ActivityLogRepository;
use App\Service\AppSettingsInterface;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PurgeActivityLogHandlerTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private function log(string $createdAt): ActivityLog
    {
        return new ActivityLog(new \DateTimeImmutable($createdAt), '10.0.0.1', 'session.login');
    }

    private function retentionDefinition(string $default): SettingDefinition
    {
        return (new SettingDefinition())
            ->setKey('audit.log_retention_days')
            ->setType(SettingType::Integer)
            ->setDefaultValue($default)
            ->setGlobalScope(true);
    }

    private function repo(): ActivityLogRepository
    {
        /** @var ActivityLogRepository $repo */
        $repo = self::getContainer()->get(ActivityLogRepository::class);

        return $repo;
    }

    private function runHandler(): void
    {
        $handler = new PurgeActivityLogHandler(
            $this->repo(),
            self::getContainer()->get(AppSettingsInterface::class),
            self::getContainer()->get('clock'),
        );
        $handler(new PurgeActivityLogMessage());
        $this->em->clear();
    }

    public function testPurgesEntriesOlderThanTheConfiguredRetentionWindow(): void
    {
        self::mockTime('2026-09-20 12:00:00');

        $this->persist(
            $this->retentionDefinition('30'),
            $this->log('2026-08-01 10:00:00'),
            $this->log('2026-09-15 10:00:00'),
        );

        $this->runHandler();

        $remaining = $this->repo()->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('2026-09-15 10:00:00', $remaining[0]->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testDoesNothingWhenNoRetentionIsConfigured(): void
    {
        $this->persist($this->log('2020-01-01 10:00:00'));

        $this->runHandler();

        self::assertCount(1, $this->repo()->findAll());
    }

    public function testDoesNothingWhenRetentionIsZero(): void
    {
        $this->persist($this->retentionDefinition('0'), $this->log('2020-01-01 10:00:00'));

        $this->runHandler();

        self::assertCount(1, $this->repo()->findAll());
    }
}
