<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del tamaño máximo de los ficheros que se suben: por fichero y por envío (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'uploads.max_file_size_mb', 'integer', '20', TRUE, FALSE, FALSE, 1, 1024, NULL, 'settings.category.uploads', 40, 10),
                (gen_random_uuid(), 'uploads.max_total_size_mb', 'integer', '100', TRUE, FALSE, FALSE, 1, 2048, NULL, 'settings.category.uploads', 40, 20)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ('uploads.max_file_size_mb', 'uploads.max_total_size_mb'))");
        $this->addSql("DELETE FROM setting_definition WHERE key IN ('uploads.max_file_size_mb', 'uploads.max_total_size_mb')");
    }
}
