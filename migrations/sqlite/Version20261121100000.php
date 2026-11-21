<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OCR opcional: columna document_file_text.ocr_page y ajuste global search.ocr, desactivado por defecto (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE document_file_text ADD COLUMN ocr_page INTEGER DEFAULT NULL');
        // The id is the UUID as 16 raw bytes (a hex blob literal), the way Doctrine's uuid type stores it on SQLite.
        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            (X'13adb3b7491afbeb3fc5665da5001680', 'search.ocr', 'choice', 'off', 1, 0, 0, NULL, NULL, 'off,on', 'settings.category.documents', 8, 20)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'search.ocr')");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key = 'search.ocr'");
        // A row under way has a status the previous code does not know: the sweep reads that file again.
        $this->addSql("DELETE FROM document_file_text WHERE status = 'ocr_pending'");
        $this->addSql('ALTER TABLE document_file_text DROP COLUMN ocr_page');
    }
}
