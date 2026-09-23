<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajuste trash.retention_days (días en la papelera antes de vaciarse), a nivel global y de centro (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            ('8c3f1d5e-2b47-4a96-9e0f-6d1a7b3c5e21', 'trash.retention_days', 'integer', '30', 1, 1, 0, 0, 3650, NULL, 'settings.category.trash', 35, 10)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'trash.retention_days')");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'trash.retention_days')");
        $this->addSql("DELETE FROM setting_definition WHERE key = 'trash.retention_days'");
    }
}
