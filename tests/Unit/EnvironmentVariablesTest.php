<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The native binary (dist/install-ubuntu.sh, start.sh…) writes its own `.env` with a fixed list of variables and does NOT ship the
 * repository's `.env`. A variable the code requires (`#[Autowire(env: 'X')]`, no `default::`) that neither has a default in
 * config/services.yaml (`env(X)`) nor is written by the installer makes every page that needs it fail on a fresh install
 * («Environment variable not found: APP_PASSWORD_BREACH_CHECK»). Add a default there when adding a variable.
 */
final class EnvironmentVariablesTest extends TestCase
{
    public function testEveryRequiredVariableHasADefaultOrIsWrittenByTheInstaller(): void
    {
        $root     = \dirname(__DIR__, 2);
        $services = (string) file_get_contents($root . '/config/services.yaml');
        $installer = (string) file_get_contents($root . '/dist/install-ubuntu.sh');

        $missing = [];
        $files   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match_all("/Autowire\\(\\s*env:\\s*'(?:[a-z_]+:)*([A-Z][A-Z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $found, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($found as $match) {
                // `default::NAME` reads as nothing when missing: always safe.
                if (str_contains($match[0], 'default::')) {
                    continue;
                }
                $name = $match[1];
                if (!str_contains($services, "env({$name}):") && !str_contains($installer, $name)) {
                    $missing[$name] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $missing, 'Variables the code requires, with no default in config/services.yaml and not written by dist/install-ubuntu.sh');
    }
}
