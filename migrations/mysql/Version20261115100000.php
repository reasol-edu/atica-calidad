<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes backup.auto_policy y backup.auto_max_size_mb (copias automáticas: política de conservación y espacio máximo), solo a nivel global (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'backup.auto_policy', 'choice', 'off', 1, 0, 0, NULL, NULL, 'off,basic,recommended,extended', 'settings.category.backup', 6, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'backup.auto_max_size_mb', 'integer', '0', 1, 0, 0, 0, 1048576, NULL, 'settings.category.backup', 6, 20)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` IN ('backup.auto_policy', 'backup.auto_max_size_mb'))");
        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('backup.auto_policy', 'backup.auto_max_size_mb')");
    }
}
