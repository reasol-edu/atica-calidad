<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Small facts about the running system kept **in files** under `var/state` (not in the database): the last automatic
 * backup, the heartbeat of the scheduler/worker. Files, because the web, the console and the worker are separate
 * processes that share the `var/` volume but not memory or a filesystem cache pool, and because the database is exactly
 * what a restore replaces.
 *
 * Uses `time()`-based epochs on purpose (no mocked clock): these are compared with real elapsed time.
 */
class SystemState
{
    public function __construct(
        #[Autowire('%app.state_dir%')]
        private readonly string $dir,
    ) {}

    /** @param array<string, mixed> $data */
    public function write(string $name, array $data): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            return;
        }

        $path = $this->path($name);
        $tmp  = $path . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR)) !== false) {
            @rename($tmp, $path);
        }
    }

    /** @return array<string, mixed>|null */
    public function read(string $name): ?array
    {
        $raw = @file_get_contents($this->path($name));
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return null;
        }

        $state = [];
        foreach ($data as $key => $value) {
            $state[(string) $key] = $value;
        }

        return $state === [] ? null : $state;
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9._-]/i', '', $name) . '.json';
    }
}
