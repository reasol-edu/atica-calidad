<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del tamaño máximo de los ficheros que se suben: por fichero y por envío (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            ('5b0e7a3c-91d4-4f62-8a1b-3c7d2e9f4a10', 'uploads.max_file_size_mb', 'integer', '20', 1, 0, 0, 1, 1024, NULL, 'settings.category.uploads', 40, 10),
            ('a7c41e62-0d3b-4b85-9f27-6e1c8d5b3f21', 'uploads.max_total_size_mb', 'integer', '100', 1, 0, 0, 1, 2048, NULL, 'settings.category.uploads', 40, 20)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ('uploads.max_file_size_mb', 'uploads.max_total_size_mb'))");
        $this->addSql("DELETE FROM setting_definition WHERE key IN ('uploads.max_file_size_mb', 'uploads.max_total_size_mb')");
    }
}
