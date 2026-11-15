<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * How many automatic backups to keep, as a few ready-made choices (the `backup.auto_policy` setting) instead of three
 * numbers to tune. Each one keeps the newest backup of each of the last N days, of the last N weeks and of the last N
 * months **that have a backup**: the nightly backup is the only one made, the older ones are just the ones that survive
 * the pruning, so a policy never costs more than daily + weekly + monthly files.
 */
enum BackupRetentionPolicy: string
{
    case Off         = 'off';
    case Basic       = 'basic';
    case Recommended = 'recommended';
    case Extended    = 'extended';

    public static function fromSetting(mixed $value): self
    {
        return \is_string($value) ? (self::tryFrom($value) ?? self::Off) : self::Off;
    }

    public function isOn(): bool
    {
        return $this !== self::Off;
    }

    /** @return array{daily: int, weekly: int, monthly: int} */
    public function tiers(): array
    {
        return match ($this) {
            self::Off         => ['daily' => 0, 'weekly' => 0, 'monthly' => 0],
            self::Basic       => ['daily' => 7, 'weekly' => 0, 'monthly' => 0],
            self::Recommended => ['daily' => 7, 'weekly' => 4, 'monthly' => 3],
            self::Extended    => ['daily' => 14, 'weekly' => 8, 'monthly' => 12],
        };
    }
}
