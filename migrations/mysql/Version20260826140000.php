<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catálogo inicial de ajustes: avisos por correo y plantillas PDF de informes (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.email_log_enabled', 'boolean', 'true', 1, 1, 0, NULL, NULL, 'settings.category.email_alerts', 10, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.log_retention_days', 'integer', '90', 1, 0, 0, 0, 3650, 'settings.category.email_alerts', 10, 20),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.pdf_template_portrait', 'pdf', '', 1, 1, 0, NULL, NULL, 'settings.category.report_templates', 20, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.pdf_template_landscape', 'pdf', '', 1, 1, 0, NULL, NULL, 'settings.category.report_templates', 20, 20)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('notifications.email_log_enabled', 'notifications.log_retention_days', 'reports.pdf_template_portrait', 'reports.pdf_template_landscape')");
    }
}
