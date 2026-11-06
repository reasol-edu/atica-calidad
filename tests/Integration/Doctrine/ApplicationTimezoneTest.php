<?php

declare(strict_types=1);

namespace App\Tests\Integration\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** `APP_TIMEZONE` fija la zona de PHP al arrancar, y la base de datos sigue recibiendo UTC. */
class ApplicationTimezoneTest extends KernelTestCase
{
    private string $previous;
    private mixed $previousServer;
    private mixed $previousEnv;

    protected function setUp(): void
    {
        $this->previous       = date_default_timezone_get();
        $this->previousServer = $_SERVER['APP_TIMEZONE'] ?? null;
        $this->previousEnv    = $_ENV['APP_TIMEZONE'] ?? null;
    }

    /** Devuelve el entorno como estaba (UTC en los tests, por .env.test): los demás tests dependen de él. */
    protected function tearDown(): void
    {
        if ($this->previousServer === null) {
            unset($_SERVER['APP_TIMEZONE']);
        } else {
            $_SERVER['APP_TIMEZONE'] = $this->previousServer;
        }
        if ($this->previousEnv === null) {
            unset($_ENV['APP_TIMEZONE']);
        } else {
            $_ENV['APP_TIMEZONE'] = $this->previousEnv;
        }
        parent::tearDown();
        date_default_timezone_set($this->previous);
    }

    public function testTheKernelAppliesTheConfiguredTimezoneAndTheDatabaseStillGetsUtc(): void
    {
        $_SERVER['APP_TIMEZONE'] = $_ENV['APP_TIMEZONE'] = 'Europe/Madrid';

        self::bootKernel();
        self::assertSame('Europe/Madrid', date_default_timezone_get());

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $stored     = $connection->fetchOne('SELECT ?', [new \DateTimeImmutable('2026-07-01 10:30:00')], ['datetime_immutable']);

        self::assertSame('2026-07-01 08:30:00', $stored);
    }

    public function testAnUnknownTimezoneFallsBackToUtc(): void
    {
        $_SERVER['APP_TIMEZONE'] = $_ENV['APP_TIMEZONE'] = 'Marte/Olympus_Mons';

        self::bootKernel();

        self::assertSame('UTC', date_default_timezone_get());
    }
}
