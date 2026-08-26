<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catálogo inicial de ajustes: avisos por correo y plantillas PDF de informes (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000001', 'notifications.email_log_enabled', 'boolean', 'true', 1, 1, 0, NULL, NULL, 'settings.category.email_alerts', 10, 10),
            ('00000000-0000-4000-8000-000000000002', 'notifications.log_retention_days', 'integer', '90', 1, 0, 0, 0, 3650, 'settings.category.email_alerts', 10, 20),
            ('00000000-0000-4000-8000-000000000003', 'reports.pdf_template_portrait', 'pdf', '', 1, 1, 0, NULL, NULL, 'settings.category.report_templates', 20, 10),
            ('00000000-0000-4000-8000-000000000004', 'reports.pdf_template_landscape', 'pdf', '', 1, 1, 0, NULL, NULL, 'settings.category.report_templates', 20, 20)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.email_log_enabled', 'notifications.log_retention_days', 'reports.pdf_template_portrait', 'reports.pdf_template_landscape')");
    }
}
