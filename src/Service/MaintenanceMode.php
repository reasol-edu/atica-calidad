<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Whether the whole application is in maintenance mode (setting maintenance.enabled, global-only):
 * only global administrators can use it then — see MaintenanceModeSubscriber. Off when the setting
 * isn't defined (e.g. a database not migrated yet), so a missing definition never locks anyone out.
 */
final class MaintenanceMode
{
    public const string SETTING = 'maintenance.enabled';

    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    public function isActive(): bool
    {
        return $this->settings->getGlobal(self::SETTING) === true;
    }
}
