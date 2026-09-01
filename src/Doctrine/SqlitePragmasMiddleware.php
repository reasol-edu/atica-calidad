<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Adjusts SQLite's PRAGMAs on every connection to tolerate concurrent writes
 * between the web server and the Messenger worker (same database file in
 * binary deployments with FrankenPHP).
 *
 *  - WAL allows concurrent reads while writing.
 *  - busy_timeout avoids "database is locked" errors by retrying for 5 s.
 *  - synchronous=NORMAL is safe under WAL and reduces fsyncs.
 *  - foreign_keys=ON makes SQLite enforce foreign key constraints
 *    (including onDelete: CASCADE), same as MySQL/PostgreSQL; SQLite
 *    ignores them by default unless explicitly enabled on each connection.
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
