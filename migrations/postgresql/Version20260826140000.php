<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catálogo inicial de ajustes: avisos por correo y plantillas PDF de informes (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'notifications.email_log_enabled', 'boolean', 'true', TRUE, TRUE, FALSE, NULL, NULL, 'settings.category.email_alerts', 10, 10),
                (gen_random_uuid(), 'notifications.log_retention_days', 'integer', '90', TRUE, FALSE, FALSE, 0, 3650, 'settings.category.email_alerts', 10, 20),
                (gen_random_uuid(), 'reports.pdf_template_portrait', 'pdf', '', TRUE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 20, 10),
                (gen_random_uuid(), 'reports.pdf_template_landscape', 'pdf', '', TRUE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 20, 20)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.email_log_enabled', 'notifications.log_retention_days', 'reports.pdf_template_portrait', 'reports.pdf_template_landscape')");
    }
}
