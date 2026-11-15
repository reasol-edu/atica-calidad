<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\AppSettingsInterface;
use App\Service\SystemState;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The nightly automatic backup (see Schedule.php) and what Administration shows about it.
 *
 * Opt-in, from the global settings: `backup.auto_policy` (off by default) says which automatic backups to keep (see
 * BackupRetentionPolicy) and `backup.auto_max_size_mb` (0 = no limit) the space they may take together. The result of every run is
 * recorded in `var/state` so the screens can say when the last one was and whether it failed — the run itself never
 * throws (a failed backup must not be retried by Messenger: several heavy runs in a row would only make things worse).
 */
class AutomaticBackup
{
    public const STATE = 'backup-auto';

    public function __construct(
        private readonly BackupLibrary $library,
        private readonly RestoreState $restoreState,
        private readonly SystemState $state,
        private readonly LoggerInterface $logger,
        private readonly AppSettingsInterface $settings,
        #[Autowire('%app.backup_stale_days%')]
        private readonly int $staleDays,
    ) {}

    public function isEnabled(): bool
    {
        return $this->policy()->isOn();
    }

    /** Which automatic backups to keep (Off = automatic backups are off). */
    public function policy(): BackupRetentionPolicy
    {
        // getGlobal(), never get(): this runs in the worker, where there is no request to resolve a centre from.
        return BackupRetentionPolicy::fromSetting($this->settings->getGlobal('backup.auto_policy'));
    }

    /** Space the automatic backups may take together, in MB (0 = no limit). */
    public function maxSizeMb(): int
    {
        $value = $this->settings->getGlobal('backup.auto_max_size_mb');

        return \is_int($value) ? max(0, $value) : 0;
    }

    /** Takes the backup and prunes the old ones. Does nothing when disabled or while a restore is running. */
    public function run(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if ($this->restoreState->isRunning()) {
            $this->logger->notice('Copia automática omitida: hay una restauración en curso.');

            return;
        }

        try {
            $backup  = $this->library->saveAutomatic();
            $deleted = $this->library->pruneAutomatic($this->policy(), $this->maxSizeMb() * 1048576);
            $this->state->write(self::STATE, ['ok' => true, 'at' => time(), 'file' => $backup->name, 'size' => $backup->size, 'pruned' => $deleted]);
            $this->logger->info('Copia automática guardada: {file} ({size} bytes); {pruned} antigua(s) eliminada(s).', ['file' => $backup->name, 'size' => $backup->size, 'pruned' => $deleted]);
        } catch (\Throwable $e) {
            $this->state->write(self::STATE, ['ok' => false, 'at' => time(), 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->logger->error('La copia automática ha fallado: {reason}', ['reason' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * What Administration shows: whether it is on, the last run, the newest backup of any kind and whether it is overdue.
     *
     * @return array{enabled: bool, policy: string, tiers: array{daily: int, weekly: int, monthly: int}, max_size_mb: int, last_run: array<string, mixed>|null, last_backup_at: int|null, stale: bool, stale_days: int}
     */
    public function status(): array
    {
        $last  = $this->library->lastBackupAt();
        $stale = $last === null || time() - $last > $this->staleDays * 86400;

        return [
            'enabled'        => $this->isEnabled(),
            'policy'         => $this->policy()->value,
            'tiers'          => $this->policy()->tiers(),
            'max_size_mb'    => $this->maxSizeMb(),
            'last_run'       => $this->state->read(self::STATE),
            'last_backup_at' => $last,
            'stale'          => $stale,
            'stale_days'     => $this->staleDays,
        ];
    }
}
