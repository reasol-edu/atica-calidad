<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Logotipo del centro (ajuste de tipo imagen, global y de centro) y márgenes de página de los documentos (SQLite)
 */
final class Version20261123140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes: logotipo del centro y márgenes de página de los documentos (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            (X'00000000000040008000000000000d03', 'centre.logo', 'image', '', 1, 1, 0, NULL, NULL, 'settings.category.document_layout', 9, 5),
            (X'00000000000040008000000000000d08', 'centre.education_authority_logo', 'image', '', 1, 1, 0, NULL, NULL, 'settings.category.document_layout', 9, 6),
            (X'00000000000040008000000000000d04', 'forms.margin_top', 'integer', '10', 1, 1, 0, 5, 40, 'settings.category.document_layout', 9, 30),
            (X'00000000000040008000000000000d05', 'forms.margin_bottom', 'integer', '10', 1, 1, 0, 5, 40, 'settings.category.document_layout', 9, 40),
            (X'00000000000040008000000000000d06', 'forms.margin_left', 'integer', '15', 1, 1, 0, 5, 40, 'settings.category.document_layout', 9, 50),
            (X'00000000000040008000000000000d07', 'forms.margin_right', 'integer', '15', 1, 1, 0, 5, 40, 'settings.category.document_layout', 9, 60)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $keys = "'centre.logo', 'centre.education_authority_logo','forms.margin_top', 'forms.margin_bottom', 'forms.margin_left', 'forms.margin_right'";
        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ({$keys}))");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key IN ({$keys})");
        // Los logotipos subidos que ya no referencia ningún valor.
        $this->addSql('DELETE FROM setting_file
            WHERE id NOT IN (SELECT file_id FROM global_setting_value WHERE file_id IS NOT NULL)
              AND id NOT IN (SELECT file_id FROM centre_setting_value WHERE file_id IS NOT NULL)
              AND id NOT IN (SELECT file_id FROM teacher_setting_value WHERE file_id IS NOT NULL)');
    }
}
