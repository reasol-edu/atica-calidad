<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convierte los ids de texto de setting_definition en UUID binarios, como los guarda Doctrine en SQLite (SQLite)';
    }

    /**
     * The settings catalogue was seeded with the UUIDs as TEXT, but Doctrine stores and compares the
     * uuid type as 16 raw bytes on SQLite, so a stored value could never point at them: saving any of
     * these settings failed with a foreign key error. Each text id becomes the same UUID as a blob,
     * and so does every reference to it in the three tables of stored values (normally none, since
     * saving was failing).
     */
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM setting_definition WHERE typeof(id) = 'text'");
        if ($ids === []) {
            return;
        }

        // The parent and its children change in the same transaction: check the keys at commit, not row by row.
        $this->addSql('PRAGMA defer_foreign_keys = ON');

        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
                continue;
            }

            $blob = "X'" . str_replace('-', '', $id) . "'";
            $this->addSql(sprintf("UPDATE setting_definition SET id = %s WHERE id = '%s'", $blob, $id));
            foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
                $this->addSql(sprintf("UPDATE %s SET definition_id = %s WHERE definition_id = '%s'", $table, $blob, $id));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // Nothing to undo: the binary ids are the right ones, and the text ones never worked.
    }
}
