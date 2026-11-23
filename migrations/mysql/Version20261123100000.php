<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajustes del encabezado y el pie de página de los documentos (modelos rellenables): texto enriquecido con tablas, con una tabla por
 * defecto (nombre y localidad, título, código, revisión y página; código y fecha en el pie) (MySQL y MariaDB)
 */
final class Version20261123100000 extends AbstractMigration
{
    private const string HEADER = '<table style="width: 100%; border-collapse: collapse"><tbody><tr><td colspan="3" style="border: 1px solid #555555; padding: 4px; text-align: center; background-color: #eef2ea"><p style="text-align: center"><strong>{centro} - {localidad}</strong></p></td></tr><tr><td colspan="3" style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center"><strong>{titulo}</strong></p></td></tr><tr><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">{codigo}</p></td><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">REV: {revision}</p></td><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">Página {pagina} de {paginas}</p></td></tr></tbody></table>';

    private const string FOOTER = '<table style="width: 100%; border-collapse: collapse"><tbody><tr><td style="border: none; padding: 2px"><p>{codigo}</p></td><td style="border: none; padding: 2px; text-align: right"><p style="text-align: right">Fecha: {fecha_revision}</p></td></tr></tbody></table>';

    public function getDescription(): string
    {
        return 'Ajustes del encabezado y el pie de página de los documentos (MySQL y MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql("INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            (UNHEX(REPLACE(UUID(), '-', '')), 'forms.document_header', 'layout', ?, 1, 1, 0, NULL, NULL, 'settings.category.document_layout', 9, 10),
            (UNHEX(REPLACE(UUID(), '-', '')), 'forms.document_footer', 'layout', ?, 1, 1, 0, NULL, NULL, 'settings.category.document_layout', 9, 20)
        ", [self::HEADER, self::FOOTER]);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` IN ('forms.document_header', 'forms.document_footer'))");
        }
        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('forms.document_header', 'forms.document_footer')");
    }
}
