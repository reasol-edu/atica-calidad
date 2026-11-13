<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts the restore as a process independent of the web request: it takes minutes and must survive the
 * browser closing the connection (and, with FrankenPHP or PHP-FPM, the worker that served the request
 * finishing).
 *
 * The process is detached with the shell itself (`nohup … &` on Linux/macOS, `start /B` on Windows)
 * rather than with Process::start(): Process' destructor kills a child still alive when the request ends.
 * Arguments are escaped, and only backup names already validated by BackupLibrary reach it.
 *
 * Only plain (unencrypted) backups get here: a password would have to travel on the command line.
 *
 * Not tested on Windows: written following the system's documentation.
 */
final class ProcessRestoreLauncher implements RestoreLauncher
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%app.restore_state_dir%')]
        private readonly string $stateDir,
    ) {}

    public function isAvailable(): bool
    {
        if (!\function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !\in_array('proc_open', $disabled, true) && !\in_array('exec', $disabled, true) && $this->phpCommand() !== null;
    }

    public function launch(string $zipPath, string $by): void
    {
        $php = $this->phpCommand();
        if ($php === null) {
            throw new BackupException('The PHP interpreter to start the restore with was not found.');
        }
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0o700, true) && !is_dir($this->stateDir)) {
            throw new BackupException('The restore state folder could not be created.');
        }

        $arguments = [...$php, $this->projectDir . '/bin/console', 'app:restore', $zipPath, '--lock-held', '--previous-backup', '--by=' . $by, '--no-interaction'];
        $log       = $this->stateDir . '/restore.log';

        $line = '\\' === \DIRECTORY_SEPARATOR
            ? \sprintf('start /B "" %s >> %s 2>&1', implode(' ', array_map($this->windowsQuote(...), $arguments)), $this->windowsQuote($log))
            : \sprintf('nohup %s >> %s 2>&1 &', implode(' ', array_map('escapeshellarg', $arguments)), escapeshellarg($log));

        // The shell returns as soon as it launches the background process; its output goes to a file, not to a pipe that closes.
        $shell = Process::fromShellCommandline($line, $this->projectDir);
        $shell->setTimeout(15);
        $shell->run();
        if (!$shell->isSuccessful()) {
            throw new BackupException('The restore process could not be started: ' . trim($shell->getErrorOutput()));
        }
    }

    public function consoleCommand(string $zipPath): string
    {
        $php = $this->phpCommand();

        return implode(' ', array_map('escapeshellarg', [...($php ?? ['php']), 'bin/console', 'app:restore', $zipPath]));
    }

    /**
     * The PHP interpreter to run the console with. The FrankenPHP binary (which is the server, not a command-line
     * PHP) needs `frankenphp php-cli`.
     *
     * @return list<string>|null
     */
    private function phpCommand(): ?array
    {
        if (str_contains(strtolower(basename(\PHP_BINARY)), 'frankenphp')) {
            return [\PHP_BINARY, 'php-cli'];
        }

        $php = (new PhpExecutableFinder())->find();

        return $php === false ? null : [$php];
    }

    private function windowsQuote(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
