<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generador de calendarios: mostrar/ocultar encabezado, pie y horas, y plantilla PDF propia (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE printable_calendar ADD show_header BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE printable_calendar ADD show_footer BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE printable_calendar ADD show_hours BOOLEAN NOT NULL DEFAULT true');
        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, category, category_order, position) VALUES
                (gen_random_uuid(), 'reports.printable_calendar_pdf_template_portrait', 'pdf', '', TRUE, TRUE, FALSE, 'settings.category.report_templates', 20, 30),
                (gen_random_uuid(), 'reports.printable_calendar_pdf_template_landscape', 'pdf', '', TRUE, TRUE, FALSE, 'settings.category.report_templates', 20, 40)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ('reports.printable_calendar_pdf_template_portrait', 'reports.printable_calendar_pdf_template_landscape'))");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key IN ('reports.printable_calendar_pdf_template_portrait', 'reports.printable_calendar_pdf_template_landscape')");

        $this->addSql('ALTER TABLE printable_calendar DROP show_header');
        $this->addSql('ALTER TABLE printable_calendar DROP show_footer');
        $this->addSql('ALTER TABLE printable_calendar DROP show_hours');
    }
}
