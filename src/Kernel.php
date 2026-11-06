<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        $booted = $this->booted;
        parent::boot();

        if (!$booted) {
            $this->applyTimezone();
        }
    }

    /**
     * PHP ignora la zona horaria del sistema operativo: sin esto usaría UTC. La de la aplicación es `APP_TIMEZONE`
     * (Europe/Madrid por defecto); un valor que PHP no conozca deja UTC.
     */
    private function applyTimezone(): void
    {
        $timezone = $this->getContainer()->getParameter('app.timezone');

        if (\in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            date_default_timezone_set($timezone);
        } else {
            date_default_timezone_set('UTC');
        }
    }
}
