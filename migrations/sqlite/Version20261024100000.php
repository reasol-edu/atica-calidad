<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajuste maintenance.enabled (modo mantenimiento), solo a nivel global (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // The id is the UUID as 16 raw bytes (a hex blob literal), the way Doctrine's uuid type stores it
        // on SQLite: a text id would make every foreign key to this row (the stored values) fail.
        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            (X'7d0c4e0a3f5b4a9e9a625b1f0d8c2e41', 'maintenance.enabled', 'boolean', 'false', 1, 0, 0, NULL, NULL, NULL, 'settings.category.maintenance', 5, 10)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key = 'maintenance.enabled'");
    }
}
