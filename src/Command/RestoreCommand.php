<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backup\BackupArchiveException;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\BackupManifest;
use App\Service\Backup\DatabaseRestoreService;
use App\Service\Backup\RestoreState;
use App\Service\Backup\SchemaMismatchException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Restores a backup made with `app:backup`, replacing all the data. While it runs the application answers
 * 503 (file lock, see RestoreGuardSubscriber) and when it ends well every open session is closed. The
 * web (Administration › Backups) starts it as a separate process with `--lock-held`, `--previous-backup`
 * and `--by`; by hand, `--unlock` removes a stuck lock.
 */
#[AsCommand(name: 'app:restore')]
class RestoreCommand extends Command
{
    public function __construct(
        private readonly DatabaseRestoreService $restoreService,
        private readonly BackupLibrary $library,
        private readonly RestoreState $state,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $t = fn (string $key): string => $this->translator->trans($key, domain: 'command');

        $this
            ->setDescription($t('restore.description'))
            ->addArgument('archive', InputArgument::OPTIONAL, $t('restore.argument.archive'))
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, $t('restore.option.password'), false)
            ->addOption('force', 'f', InputOption::VALUE_NONE, $t('restore.option.force'))
            ->addOption('previous-backup', null, InputOption::VALUE_NONE, $t('restore.option.previous_backup'))
            ->addOption('unlock', null, InputOption::VALUE_NONE, $t('restore.option.unlock'))
            ->addOption('by', null, InputOption::VALUE_REQUIRED, $t('restore.option.by'))
            // Internal: the web already took the lock (so the site is in maintenance before this process starts).
            ->addOption('lock-held', null, InputOption::VALUE_NONE, $t('restore.option.lock_held'))
            ->setHelp($t('restore.help'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        if ($input->getOption('unlock')) {
            $io->success($t($this->state->forceUnlock() ? 'restore.unlocked' : 'restore.not_locked'));

            return Command::SUCCESS;
        }

        $lockHeld = (bool) $input->getOption('lock-held');

        // When the web took the lock, this process releases it whatever happens.
        try {
            return $this->restoreArchive($input, $io, $t, $lockHeld);
        } finally {
            if ($lockHeld) {
                $this->state->release();
            }
        }
    }

    /** @param \Closure(string, array<string, mixed>=): string $t */
    private function restoreArchive(InputInterface $input, SymfonyStyle $io, \Closure $t, bool $lockHeld): int
    {
        $archiveArg = $input->getArgument('archive');
        $archive    = \is_string($archiveArg) ? $archiveArg : '';
        $force      = (bool) $input->getOption('force');
        $by         = $input->getOption('by');
        $by         = \is_string($by) && $by !== '' ? $by : 'console';

        if ($archive === '') {
            $io->error($t('restore.error.no_archive'));

            return Command::INVALID;
        }

        if (!$force && !$lockHeld && !$input->isInteractive()) {
            $io->error($t('restore.error.needs_force'));

            return Command::INVALID;
        }

        try {
            $password = $this->resolvePassword($input, $io, $t);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        try {
            $manifest = $this->restoreService->inspect($archive, $password);
        } catch (BackupArchiveException $e) {
            return $this->fail($io, $t('restore.error.archive', ['%reason%' => $e->getMessage()]), $by, $archive, $lockHeld);
        }

        $io->section($t('restore.about'));
        $io->definitionList(
            [$t('restore.about.created') => $manifest->createdAt->format('Y-m-d H:i')],
            [$t('restore.about.app_version') => $manifest->appVersion],
            [$t('restore.about.schema') => $manifest->schemaVersion ?? '—'],
            [$t('restore.about.contents') => $t('restore.about.contents_value', [
                '%tables%' => $manifest->tableCount(),
                '%rows%'   => number_format($manifest->totalRows(), 0, ',', '.'),
            ])],
        );

        if (!$lockHeld && !$this->confirmDestruction($input, $io, $t)) {
            $io->warning($t('restore.aborted'));

            return Command::SUCCESS;
        }

        // The web takes the lock before launching; the console takes it here. Released always, whatever happens.
        if (!$lockHeld && !$this->state->acquire($by, basename($archive))) {
            $io->error($t('restore.error.running'));

            return Command::FAILURE;
        }

        try {
            $this->state->attachPid((int) getmypid());

            return $this->perform($io, $t, $manifest, $archive, $password, $force, $by, (bool) $input->getOption('previous-backup'));
        } finally {
            if (!$lockHeld) {
                $this->state->release();
            }
        }
    }

    /** @param \Closure(string, array<string, mixed>=): string $t */
    private function perform(SymfonyStyle $io, \Closure $t, BackupManifest $manifest, string $archive, ?string $password, bool $force, string $by, bool $previousBackup): int
    {
        try {
            if ($previousBackup) {
                $previous = $this->library->save('atica-calidad-before-restore');
                $io->note($t('restore.previous_saved', ['%name%' => $previous->name]));
            }

            $this->logger->warning('Backup restore started.', ['by' => $by, 'file' => basename($archive), 'copy_version' => $manifest->appVersion]);

            $restore = $this->restoreService->restore($archive, $password, $force);
        } catch (SchemaMismatchException $e) {
            return $this->fail($io, $t('restore.error.schema_mismatch', [
                '%backup%'   => $e->backupSchemaVersion ?? '—',
                '%database%' => $e->databaseSchemaVersion ?? '—',
            ]), $by, $archive, true);
        } catch (BackupArchiveException $e) {
            return $this->fail($io, $t('restore.error.archive', ['%reason%' => $e->getMessage()]), $by, $archive, true);
        } catch (BackupException $e) {
            return $this->fail($io, $t('restore.error.previous_failed', ['%reason%' => $e->getMessage()]), $by, $archive, true);
        } catch (\Throwable $e) {
            return $this->fail($io, $t('restore.error.failed', ['%reason%' => $e->getMessage()]), $by, $archive, true);
        }

        $this->state->markRestored();
        $this->state->writeResult(['ok' => true, 'by' => $by, 'file' => basename($archive), 'tables' => $restore->tableCount(), 'rows' => $restore->totalRows()]);
        $this->logger->warning('Backup restore finished.', ['by' => $by, 'file' => basename($archive), 'rows' => $restore->totalRows()]);

        $io->success($t('restore.success'));
        $io->writeln('  ' . $t('restore.summary', [
            '%tables%' => $restore->tableCount(),
            '%rows%'   => number_format($restore->totalRows(), 0, ',', '.'),
        ]));
        if ($restore->schemaOverridden) {
            $io->warning($t('restore.schema_overridden'));
        }
        $io->newLine();
        $io->note($t('restore.note.restart'));

        return Command::SUCCESS;
    }

    /**
     * Fails leaving a trace: in the server log and, for the administration screen, in the result of the
     * last restore (only when a restore was actually attempted or launched from the web).
     */
    private function fail(SymfonyStyle $io, string $message, string $by, string $archive, bool $recordResult): int
    {
        $io->error($message);
        $this->logger->error('Backup restore failed.', ['by' => $by, 'file' => basename($archive), 'reason' => $message]);
        if ($recordResult) {
            $this->state->writeResult(['ok' => false, 'by' => $by, 'file' => basename($archive), 'message' => $message]);
        }

        return Command::FAILURE;
    }

    /**
     * Same tri-state `--password` as app:backup: absent → assume plain archive; valueless → ask
     * once on the console; with a value → use it.
     *
     * @param \Closure(string, array<string, mixed>=): string $t
     *
     * @throws \RuntimeException empty password, or asked to prompt with no console
     */
    private function resolvePassword(InputInterface $input, SymfonyStyle $io, \Closure $t): ?string
    {
        $option = $input->getOption('password');

        if ($option === false) {
            return null;
        }

        if (\is_string($option)) {
            if ($option === '') {
                throw new \RuntimeException($t('backup.error.password_empty'));
            }

            return $option;
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException($t('backup.error.password_required_non_interactive'));
        }

        $entered = $io->askHidden($t('restore.ask.password'));
        if (!\is_string($entered) || $entered === '') {
            throw new \RuntimeException($t('backup.error.password_empty'));
        }

        return $entered;
    }

    /**
     * @param \Closure(string, array<string, mixed>=): string $t
     */
    private function confirmDestruction(InputInterface $input, SymfonyStyle $io, \Closure $t): bool
    {
        if ((bool) $input->getOption('force')) {
            return true;
        }

        $io->warning($t('restore.warning'));

        return $io->confirm($t('restore.confirm'), false);
    }
}
