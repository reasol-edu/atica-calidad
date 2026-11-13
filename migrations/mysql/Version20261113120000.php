<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Elimina el generador de calendarios (Utilidades), que se ha trasladado a otra aplicación: sus tres
 * tablas (con los calendarios guardados por cada docente) y sus dos ajustes de plantilla PDF, con los
 * valores y los ficheros que ya no usa nadie (MySQL / MariaDB)
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elimina el generador de calendarios: tablas printable_calendar* y ajustes de plantilla PDF (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` IN ('reports.printable_calendar_pdf_template_portrait', 'reports.printable_calendar_pdf_template_landscape'))");
        }
        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('reports.printable_calendar_pdf_template_portrait', 'reports.printable_calendar_pdf_template_landscape')");
        // Las plantillas PDF subidas a esos ajustes: ya no las referencia ningún valor.
        $this->addSql('DELETE FROM setting_file
            WHERE id NOT IN (SELECT file_id FROM global_setting_value WHERE file_id IS NOT NULL)
              AND id NOT IN (SELECT file_id FROM centre_setting_value WHERE file_id IS NOT NULL)
              AND id NOT IN (SELECT file_id FROM teacher_setting_value WHERE file_id IS NOT NULL)');

        $this->addSql('DROP TABLE printable_calendar_date');
        $this->addSql('DROP TABLE printable_calendar_period');
        $this->addSql('DROP TABLE printable_calendar');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->throwIrreversibleMigrationException('Los calendarios guardados y las plantillas PDF eliminados no se pueden recuperar.');
    }
}
