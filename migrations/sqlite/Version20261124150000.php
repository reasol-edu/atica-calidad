<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Actividades: nombre de las entregas con la ruta completa del elemento del catálogo o solo con el de la hoja (SQLite). */
final class Version20261124150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actividades: submission_name_full_path, el nombre de las entregas lleva la ruta completa del elemento o solo el de la hoja (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // ADD COLUMN, not the table rebuild Doctrine's diff proposes: the activity table is referenced by many others.
        // 1: lo que hacían todas hasta ahora.
        $this->addSql('ALTER TABLE activity ADD COLUMN submission_name_full_path BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE activity DROP COLUMN submission_name_full_path');
    }
}
