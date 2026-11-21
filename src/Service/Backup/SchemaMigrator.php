<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\MigrationFactory;
use Doctrine\Migrations\Version\Version;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Puts the database schema at the migration version asked for, so a backup made with an earlier version of the application
 * can be restored: everything is **dropped and migrated from scratch** up to the version of the backup (no `down()` is ever
 * run, they would be fragile), the data is loaded, and then the rest of the migrations are applied, which transforms the
 * backup's data into the current structure.
 *
 * None of it is transactional (MySQL commits DDL by itself): if it fails, the database is left half way and only restoring a
 * backup fixes it. That is why whoever uses this must have saved a copy of the current data first.
 *
 * Every operation builds a new Doctrine Migrations factory: its migration objects are "frozen" once they run and cannot run
 * again, and its metadata storage believes the versions table exists even after the tables were dropped.
 */
class SchemaMigrator
{
    /** @var array<string, true>|null migration versions this application has, by class name */
    private ?array $known = null;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $configured,
    ) {}

    /** Whether the migration is one of those this version of the application ships (a backup from a newer version is not). */
    public function isKnown(string $version): bool
    {
        return isset($this->knownVersions()[$version]);
    }

    /** The newest migration this version of the application ships. */
    public function latest(): string
    {
        $known = array_keys($this->knownVersions());
        sort($known);

        return (string) end($known);
    }

    /** Drops every table and migrates from scratch up to `$version` (included). */
    public function rebuildAt(string $version): void
    {
        $this->dropEverything();
        $this->migrateTo($version);
    }

    /** Applies the pending migrations up to the newest one. */
    public function migrateToLatest(): void
    {
        $this->migrateTo($this->latest());
    }

    /** @return array<string, true> */
    private function knownVersions(): array
    {
        if ($this->known === null) {
            $this->known = [];
            foreach ($this->factory()->getMigrationPlanCalculator()->getMigrations()->getItems() as $migration) {
                $this->known[(string) $migration->getVersion()] = true;
            }
        }

        return $this->known;
    }

    private function migrateTo(string $version): void
    {
        $factory = $this->factory();
        $factory->getMetadataStorage()->ensureInitialized();

        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));
        if ($plan->getItems() === []) {
            return;
        }

        try {
            $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(false));
        } finally {
            // MySQL commits DDL by itself and Doctrine never closes the transaction it opened for each migration: the
            // connection's nesting level stays up, and the next transaction (the one that loads the data) would use savepoints
            // that do not exist. Closing the connection resets it (it reopens on its own).
            if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform && $this->connection->getTransactionNestingLevel() > 0) {
                $this->connection->close();
            }
        }
    }

    /** A new factory with the same configuration (migrations folder, versions table) and the same connection. */
    private function factory(): DependencyFactory
    {
        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($this->configured->getConfiguration()),
            new ExistingConnection($this->connection),
        );
        // The bundle's one builds the migrations with their dependencies from the container.
        $factory->setService(MigrationFactory::class, $this->configured->getMigrationFactory());

        return $factory;
    }

    private function dropEverything(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql    = $platform instanceof AbstractMySQLPlatform;
        $sqlite   = $platform instanceof SQLitePlatform;

        $names = array_values(array_filter(
            array_map(static fn (string $name): string => trim($name, '"`'), $this->connection->createSchemaManager()->listTableNames()),
            static fn (string $name): bool => !str_starts_with($name, 'sqlite_'),
        ));

        // With foreign keys on the tables would have to be dropped in dependency order (and there are cycles).
        if ($mysql) {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        } elseif ($sqlite) {
            $this->connection->executeStatement('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($names as $name) {
                $this->connection->executeStatement(\sprintf('DROP TABLE %s%s', $this->connection->quoteSingleIdentifier($name), $mysql || $sqlite ? '' : ' CASCADE'));
            }
        } finally {
            if ($mysql) {
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($sqlite) {
                $this->connection->executeStatement('PRAGMA foreign_keys = ON');
            }
        }
    }
}
