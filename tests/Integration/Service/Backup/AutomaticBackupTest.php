<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Backup;

use App\Entity\GlobalSettingValue;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Service\AppSettings;
use App\Service\Backup\AutomaticBackup;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\BackupRetentionPolicy;
use App\Service\SystemState;
use App\Tests\Integration\RepositoryTestCase;

/** Nightly automatic backup: retention only touches automatic copies, no partial archives, status for Administration. */
final class AutomaticBackupTest extends RepositoryTestCase
{
    private BackupLibrary $library;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BackupLibrary $library */
        $library       = self::getContainer()->get(BackupLibrary::class);
        $this->library = $library;
        $this->dir     = $library->directory();

        // The state files live in the test cache folder and outlive a test: start from a clean slate.
        /** @var string $stateDir */
        $stateDir = self::getContainer()->getParameter('app.state_dir');
        array_map('unlink', glob($stateDir . '/*.json') ?: []);
    }

    public function testSavingAnAutomaticBackupLeavesNoPartialFile(): void
    {
        $stored = $this->library->saveAutomatic();

        self::assertMatchesRegularExpression('/^atica-calidad-auto-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $stored->name);
        self::assertFileExists($this->dir . '/' . $stored->name);
        self::assertSame([], glob($this->dir . '/*.part') ?: []);
        self::assertTrue($stored->isRestorable());
    }

    public function testBasicPolicyKeepsTheLastSevenDaysAndNeverTouchesManualOnes(): void
    {
        $manual = $this->library->save();
        foreach (range(1, 10) as $day) {
            $this->makeAutomatic(\sprintf('2026-03-%02d', $day), $manual->name);
        }
        // Someone's own file with a similar name but not the pattern: left alone too.
        copy($this->dir . '/' . $manual->name, $this->dir . '/atica-calidad-auto-importante.zip');

        self::assertSame(3, $this->library->pruneAutomatic(BackupRetentionPolicy::Basic));

        foreach ([1, 2, 3] as $day) {
            self::assertFileDoesNotExist($this->auto(\sprintf('2026-03-%02d', $day)));
        }
        foreach ([4, 5, 6, 7, 8, 9, 10] as $day) {
            self::assertFileExists($this->auto(\sprintf('2026-03-%02d', $day)));
        }
        self::assertFileExists($this->dir . '/' . $manual->name);
        self::assertFileExists($this->dir . '/atica-calidad-auto-importante.zip');
    }

    public function testRecommendedPolicyAlsoKeepsTheNewestOfEachWeek(): void
    {
        $source = $this->library->save();
        foreach (range(1, 31) as $day) { // 2026-03-01 is a Sunday: weeks end on 29th (W13), 22nd (W12), 15th (W11)...
            $this->makeAutomatic(\sprintf('2026-03-%02d', $day), $source->name);
        }

        $this->library->pruneAutomatic(BackupRetentionPolicy::Recommended);

        // Last 7 days (25–31) + the newest of the three weeks before the current one (29 is already in; 22 and 15 are new).
        foreach ([15, 22, 25, 26, 27, 28, 29, 30, 31] as $day) {
            self::assertFileExists($this->auto(\sprintf('2026-03-%02d', $day)), "day $day should be kept");
        }
        foreach ([1, 14, 16, 21, 23, 24] as $day) {
            self::assertFileDoesNotExist($this->auto(\sprintf('2026-03-%02d', $day)), "day $day should be pruned");
        }
        self::assertCount(9, glob($this->dir . '/atica-calidad-auto-*.zip') ?: []);
    }

    public function testRecommendedPolicyAlsoKeepsTheNewestOfEachMonthAndDropsOlderOnes(): void
    {
        $source = $this->library->save();
        foreach (['2026-01-15', '2026-02-15', '2026-03-15', '2026-05-01', '2026-05-02', '2026-05-03', '2026-05-04', '2026-05-05', '2026-05-06', '2026-05-07'] as $date) {
            $this->makeAutomatic($date, $source->name);
        }

        // Daily: May 1–7. Weekly: May 3, Mar 15, Feb 15 (the 4th week with a backup is Jan 15's). Monthly: May, Mar, Feb.
        self::assertSame(1, $this->library->pruneAutomatic(BackupRetentionPolicy::Recommended));

        self::assertFileDoesNotExist($this->auto('2026-01-15'));
        self::assertFileExists($this->auto('2026-02-15'));
        self::assertFileExists($this->auto('2026-03-15'));
    }

    public function testThePolicyUsesTheDateInTheNameNotTheFileTime(): void
    {
        $source = $this->library->save();
        $this->makeAutomatic('2026-03-01', $source->name);
        $this->makeAutomatic('2026-03-02', $source->name);
        touch($this->auto('2026-03-01'), time() + 3600); // a copy of the folder gives it a newer mtime: it must not matter
        touch($this->auto('2026-03-02'), time() - 400 * 86400);

        $this->library->pruneAutomatic(BackupRetentionPolicy::Basic);

        self::assertFileExists($this->auto('2026-03-01'));
        self::assertFileExists($this->auto('2026-03-02'));
        self::assertSame(0, $this->library->pruneAutomatic(BackupRetentionPolicy::Off));
    }

    public function testStaleLeftoverOfAnInterruptedRunIsRemovedButARecentOneIsKept(): void
    {
        @mkdir($this->dir, 0o775, true);
        file_put_contents($this->dir . '/atica-calidad-auto-2026-01-01-023000.zip.part', 'x');
        touch($this->dir . '/atica-calidad-auto-2026-01-01-023000.zip.part', time() - 8 * 3600);
        file_put_contents($this->dir . '/atica-calidad-auto-2026-01-02-023000.zip.part', 'x');

        $this->library->pruneAutomatic(BackupRetentionPolicy::Basic);

        self::assertFileDoesNotExist($this->dir . '/atica-calidad-auto-2026-01-01-023000.zip.part');
        self::assertFileExists($this->dir . '/atica-calidad-auto-2026-01-02-023000.zip.part');
    }

    public function testLastBackupIsTheNewestZipAndNullWhenThereAreNone(): void
    {
        self::assertNull($this->library->lastBackupAt());

        $old = $this->library->save();
        touch($this->dir . '/' . $old->name, time() - 10 * 86400);
        file_put_contents($this->dir . '/atica-calidad-auto-2026-01-01-023000.zip.part', 'x'); // does not count
        self::assertFileExists($this->dir . '/atica-calidad-auto-2026-01-01-023000.zip.part');

        self::assertEqualsWithDelta(time() - 10 * 86400, $this->library->lastBackupAt(), 2);
    }

    public function testStatusWarnsWhenThereIsNoRecentBackup(): void
    {
        $service = $this->service();

        self::assertTrue($service->status()['stale']);
        self::assertNull($service->status()['last_backup_at']);

        $this->library->save();
        self::assertFalse($service->status()['stale']);

        touch($this->dir . '/' . $this->library->list()[0]->name, time() - 8 * 86400);
        self::assertTrue($service->status()['stale']);
    }

    public function testRunDoesNothingWhenAutomaticBackupsAreOff(): void
    {
        $service = $this->service();
        self::assertFalse($service->isEnabled());

        $service->run();

        self::assertSame([], $this->library->list());
        self::assertNull($service->status()['last_run']);
    }

    public function testRunSavesPrunesAndRecordsTheResultWhenOn(): void
    {
        $service = $this->service(BackupRetentionPolicy::Basic);
        $source  = $this->library->save();
        foreach (range(1, 8) as $day) {
            $this->makeAutomatic(\sprintf('2026-01-%02d', $day), $source->name);
        }

        $service->run();

        // 8 old ones + the new one = 9 automatic; Basic keeps 7 days: the new one and the 6 newest old ones.
        self::assertCount(7, glob($this->dir . '/atica-calidad-auto-*.zip') ?: []);
        $run = $service->status()['last_run'];
        self::assertTrue($run['ok']);
        self::assertSame(2, $run['pruned']);
        self::assertFalse($service->status()['stale']);
    }

    public function testRunRecordsAFailureWithoutThrowing(): void
    {
        $service = $this->service(BackupRetentionPolicy::Basic);
        // A file where the backups folder should be makes the backup impossible.
        $this->removeDir($this->dir);
        file_put_contents($this->dir, 'not a directory');

        try {
            $service->run();
        } finally {
            @unlink($this->dir);
        }

        $run = $service->status()['last_run'];
        self::assertFalse($run['ok']);
        self::assertNotSame('', $run['error']);
    }

    public function testSizeLimitDeletesTheOldestUntilTheyFitButAlwaysKeepsTheNewest(): void
    {
        $source = $this->library->save();
        $size   = (int) filesize($this->dir . '/' . $source->name);
        foreach (range(1, 4) as $day) {
            $this->makeAutomatic(\sprintf('2026-01-%02d', $day), $source->name);
        }

        // Room for two and a half copies: the two newest stay.
        self::assertSame(2, $this->library->pruneAutomatic(BackupRetentionPolicy::Basic, (int) ($size * 2.5)));
        self::assertCount(2, glob($this->dir . '/atica-calidad-auto-*.zip') ?: []);
        self::assertFileExists($this->auto('2026-01-04'));

        // A limit smaller than one copy still leaves the newest.
        self::assertSame(1, $this->library->pruneAutomatic(BackupRetentionPolicy::Basic, 1));
        self::assertSame([$this->auto('2026-01-04')], glob($this->dir . '/atica-calidad-auto-*.zip'));
        // The manual one is never counted nor deleted.
        self::assertFileExists($this->dir . '/' . $source->name);
    }

    public function testSettingsDriveTheStatus(): void
    {
        $service = $this->service(BackupRetentionPolicy::Recommended, maxMb: 300);

        $status = $service->status();
        self::assertTrue($status['enabled']);
        self::assertSame('recommended', $status['policy']);
        self::assertSame(['daily' => 7, 'weekly' => 4, 'monthly' => 3], $status['tiers']);
        self::assertSame(300, $status['max_size_mb']);
    }

    public function testThePolicyAndTheSizeLimitAreReadFromTheRealGlobalSettings(): void
    {
        $policy = (new SettingDefinition())->setKey('backup.auto_policy')->setType(SettingType::Choice)
            ->setDefaultValue('off')->setChoices('off,basic,recommended,extended');
        $size = (new SettingDefinition())->setKey('backup.auto_max_size_mb')->setType(SettingType::Integer)
            ->setDefaultValue('0')->setMinValue(0)->setMaxValue(1048576);
        $this->persist($policy);
        $this->persist($size);

        /** @var AppSettings $settings */
        $settings = self::getContainer()->get(AppSettings::class);
        $settings->invalidate();

        /** @var \App\Service\Backup\RestoreState $restore */
        $restore = self::getContainer()->get(\App\Service\Backup\RestoreState::class);
        /** @var SystemState $state */
        $state   = self::getContainer()->get(SystemState::class);
        $service = new AutomaticBackup($this->library, $restore, $state, new \Psr\Log\NullLogger(), $settings, 7);

        self::assertSame(BackupRetentionPolicy::Off, $service->policy()); // the default: off
        self::assertSame(0, $service->maxSizeMb());

        $this->persist((new GlobalSettingValue())->setDefinition($policy)->setValue('recommended'));
        $this->persist((new GlobalSettingValue())->setDefinition($size)->setValue('500'));
        $settings->invalidate();

        self::assertSame(BackupRetentionPolicy::Recommended, $service->policy());
        self::assertTrue($service->isEnabled());
        self::assertSame(500, $service->maxSizeMb());
    }

    public function testAnUnknownPolicyValueCountsAsOff(): void
    {
        self::assertSame(BackupRetentionPolicy::Off, BackupRetentionPolicy::fromSetting('whatever'));
        self::assertSame(BackupRetentionPolicy::Off, BackupRetentionPolicy::fromSetting(null));
        self::assertSame(BackupRetentionPolicy::Extended, BackupRetentionPolicy::fromSetting('extended'));
    }

    /** Copies an existing zip as the automatic backup of that day (02:30). */
    private function makeAutomatic(string $date, string $sourceName): void
    {
        copy($this->dir . '/' . $sourceName, $this->auto($date));
    }

    private function auto(string $date): string
    {
        return \sprintf('%s/atica-calidad-auto-%s-023000.zip', $this->dir, $date);
    }

    private function service(BackupRetentionPolicy $policy = BackupRetentionPolicy::Off, int $maxMb = 0): AutomaticBackup
    {
        /** @var \App\Service\Backup\RestoreState $restore */
        $restore = self::getContainer()->get(\App\Service\Backup\RestoreState::class);
        /** @var SystemState $state */
        $state = self::getContainer()->get(SystemState::class);

        $settings = $this->createStub(\App\Service\AppSettingsInterface::class);
        $settings->method('getGlobal')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'backup.auto_policy'      => $policy->value,
            'backup.auto_max_size_mb' => $maxMb,
            default                   => null,
        });

        return new AutomaticBackup($this->library, $restore, $state, new \Psr\Log\NullLogger(), $settings, 7);
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (!\in_array(basename($f), ['.', '..'], true)) {
                is_dir($f) ? $this->removeDir($f) : @unlink($f);
            }
        }
        @rmdir($dir);
    }
}
