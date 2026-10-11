<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del tamaño máximo de los ficheros que se suben: por fichero y por envío (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'uploads.max_file_size_mb', 'integer', '20', 1, 0, 0, 1, 1024, NULL, 'settings.category.uploads', 40, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'uploads.max_total_size_mb', 'integer', '100', 1, 0, 0, 1, 2048, NULL, 'settings.category.uploads', 40, 20)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` IN ('uploads.max_file_size_mb', 'uploads.max_total_size_mb'))");
        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('uploads.max_file_size_mb', 'uploads.max_total_size_mb')");
    }
}
