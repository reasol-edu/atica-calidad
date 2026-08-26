<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Ajusta los PRAGMA de SQLite en cada conexión para tolerar la escritura
 * concurrente entre el servidor web y el worker de Messenger (mismo fichero
 * de base de datos en los despliegues binarios con FrankenPHP).
 *
 *  - WAL permite lecturas concurrentes mientras se escribe.
 *  - busy_timeout evita errores «database is locked» reintentando 5 s.
 *  - synchronous=NORMAL es seguro bajo WAL y reduce los fsync.
 *  - foreign_keys=ON hace que SQLite aplique las constraints de clave ajena
 *    (incluido onDelete: CASCADE), igual que MySQL/PostgreSQL; SQLite las
 *    ignora por defecto si no se activa explícitamente en cada conexión.
 */
final class SqlitePragmasMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): DriverConnection
            {
                $connection = parent::connect($params);

                $driver = $params['driver'] ?? '';
                if (str_contains($driver, 'sqlite')) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA busy_timeout = 5000');
                    $connection->exec('PRAGMA synchronous = NORMAL');
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
