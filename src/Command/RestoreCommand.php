<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backup\BackupArchiveException;
use App\Service\Backup\DatabaseRestoreService;
use App\Service\Backup\SchemaMismatchException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:restore')]
class RestoreCommand extends Command
{
    public function __construct(
        private readonly DatabaseRestoreService $restoreService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $t = fn (string $key): string => $this->translator->trans($key, domain: 'command');

        $this
            ->setDescription($t('restore.description'))
            ->addArgument('archive', InputArgument::REQUIRED, $t('restore.argument.archive'))
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, $t('restore.option.password'), false)
            ->addOption('force', 'f', InputOption::VALUE_NONE, $t('restore.option.force'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        $archiveArg = $input->getArgument('archive');
        $archive    = \is_string($archiveArg) ? $archiveArg : '';
        $force      = (bool) $input->getOption('force');

        if (!$force && !$input->isInteractive()) {
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
            $io->error($t('restore.error.archive', ['%reason%' => $e->getMessage()]));

            return Command::FAILURE;
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

        if (!$this->confirmDestruction($input, $io, $t)) {
            $io->warning($t('restore.aborted'));

            return Command::SUCCESS;
        }

        try {
            $restore = $this->restoreService->restore($archive, $password, $force);
        } catch (SchemaMismatchException $e) {
            $io->error($t('restore.error.schema_mismatch', [
                '%backup%'   => $e->backupSchemaVersion ?? '—',
                '%database%' => $e->databaseSchemaVersion ?? '—',
            ]));

            return Command::FAILURE;
        } catch (BackupArchiveException $e) {
            $io->error($t('restore.error.archive', ['%reason%' => $e->getMessage()]));

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($t('restore.error.failed', ['%reason%' => $e->getMessage()]));

            return Command::FAILURE;
        }

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
