<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes backup.auto_policy y backup.auto_max_size_mb (copias automáticas: política de conservación y espacio máximo), solo a nivel global (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'backup.auto_policy', 'choice', 'off', TRUE, FALSE, FALSE, NULL, NULL, 'off,basic,recommended,extended', 'settings.category.backup', 6, 10),
                (gen_random_uuid(), 'backup.auto_max_size_mb', 'integer', '0', TRUE, FALSE, FALSE, 0, 1048576, NULL, 'settings.category.backup', 6, 20)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ('backup.auto_policy', 'backup.auto_max_size_mb'))");
        $this->addSql("DELETE FROM setting_definition WHERE key IN ('backup.auto_policy', 'backup.auto_max_size_mb')");
    }
}
