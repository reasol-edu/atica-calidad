<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backup\DatabaseBackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:backup')]
class BackupCommand extends Command
{
    public function __construct(
        private readonly DatabaseBackupService $backupService,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $t = fn (string $key): string => $this->translator->trans($key, domain: 'command');

        $this
            ->setDescription($t('backup.description'))
            ->addArgument('destination', InputArgument::OPTIONAL, $t('backup.argument.destination'))
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, $t('backup.option.password'), false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        $destinationArg = $input->getArgument('destination');
        $destination    = \is_string($destinationArg) && trim($destinationArg) !== ''
            ? trim($destinationArg)
            : $this->projectDir . '/var/backups';

        [$directory, $filename] = $this->resolveTarget($destination);

        try {
            $password = $this->resolvePassword($input, $io, $t);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        try {
            $backup = $this->backupService->create($directory, $filename, $password);
        } catch (\Throwable $e) {
            $io->error($t('backup.error.failed', ['%reason%' => $e->getMessage()]));

            return Command::FAILURE;
        }

        $io->success($t('backup.success', ['%path%' => $backup->path]));
        $io->writeln('  ' . $t('backup.summary', [
            '%tables%' => $backup->tableCount(),
            '%rows%'   => number_format($backup->totalRows(), 0, ',', '.'),
            '%size%'   => $this->humanBytes($backup->bytes),
        ]));
        $io->newLine();
        $io->note($t($backup->encrypted ? 'backup.notice.encrypted' : 'backup.notice.unencrypted'));

        return Command::SUCCESS;
    }

    /**
     * Tri-state `--password`: absent (default `false`) → no encryption; given without a value
     * (`null`) → ask on the console, twice, and require a match; given with a value → use it.
     *
     * @param \Closure(string, array<string, mixed>=): string $t
     *
     * @throws \RuntimeException on an empty, mismatched, or non-interactively-missing password
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

        // `--password` with no value: prompt for it.
        if (!$input->isInteractive()) {
            throw new \RuntimeException($t('backup.error.password_required_non_interactive'));
        }

        $first   = $io->askHidden($t('backup.ask.password'));
        $confirm = $io->askHidden($t('backup.ask.password_confirm'));

        if (!\is_string($first) || $first === '') {
            throw new \RuntimeException($t('backup.error.password_empty'));
        }
        if ($first !== $confirm) {
            throw new \RuntimeException($t('backup.error.password_mismatch'));
        }

        return $first;
    }

    /**
     * A destination ending in `.zip` is taken as the full archive path; anything else is a
     * directory the archive gets a timestamped name inside.
     *
     * @return array{0: string, 1: ?string}
     */
    private function resolveTarget(string $destination): array
    {
        if (str_ends_with(strtolower($destination), '.zip')) {
            return [\dirname($destination), basename($destination)];
        }

        return [$destination, null];
    }

    private function humanBytes(int $bytes): string
    {
        $units    = ['B', 'KiB', 'MiB', 'GiB'];
        $exponent = $bytes > 0 ? min((int) floor(log($bytes, 1024)), \count($units) - 1) : 0;

        return number_format($bytes / (1024 ** $exponent), $exponent === 0 ? 0 : 1, ',', '.') . ' ' . $units[$exponent];
    }
}
