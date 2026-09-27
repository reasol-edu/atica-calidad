<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generador de calendarios: mostrar/ocultar encabezado, pie y horas de cada día, y su propia
 * plantilla PDF configurable en ajustes del centro (SQLite)
 */
final class Version20261002090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generador de calendarios: mostrar/ocultar encabezado, pie y horas, y plantilla PDF propia (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE printable_calendar ADD COLUMN show_header BOOLEAN NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE printable_calendar ADD COLUMN show_footer BOOLEAN NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE printable_calendar ADD COLUMN show_hours BOOLEAN NOT NULL DEFAULT 1');
        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, category, category_order, position) VALUES
            ('15afb15a-3b9c-43e2-b312-9d348f8228c0', 'reports.printable_calendar_pdf_template_portrait', 'pdf', '', 1, 1, 0, 'settings.category.report_templates', 20, 30),
            ('3319786c-c3fe-4e97-a044-77eba91d19ba', 'reports.printable_calendar_pdf_template_landscape', 'pdf', '', 1, 1, 0, 'settings.category.report_templates', 20, 40)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ('reports.printable_calendar_pdf_template_portrait', 'reports.printable_calendar_pdf_template_landscape'))");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key IN ('reports.printable_calendar_pdf_template_portrait', 'reports.printable_calendar_pdf_template_landscape')");

        $this->addSql('ALTER TABLE printable_calendar DROP COLUMN show_header');
        $this->addSql('ALTER TABLE printable_calendar DROP COLUMN show_footer');
        $this->addSql('ALTER TABLE printable_calendar DROP COLUMN show_hours');
    }
}
