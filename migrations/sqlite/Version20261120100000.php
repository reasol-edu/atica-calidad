<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajuste documents.min_revision (número mínimo de revisión de un documento: 0 o 1), global y de centro (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // The id is the UUID as 16 raw bytes (a hex blob literal), the way Doctrine's uuid type stores it on SQLite.
        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            (X'7378fec9bee6de69ca76b42bd3cd3f87', 'documents.min_revision', 'choice', '1', 1, 1, 0, NULL, NULL, '0,1', 'settings.category.documents', 8, 10)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'documents.min_revision')");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key = 'documents.min_revision'");
    }
}
