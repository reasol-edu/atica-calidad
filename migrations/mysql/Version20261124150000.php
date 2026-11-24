<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Actividades: nombre de las entregas con la ruta completa del elemento del catálogo o solo con el de la hoja (MySQL/MariaDB). */
final class Version20261124150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actividades: submission_name_full_path, el nombre de las entregas lleva la ruta completa del elemento o solo el de la hoja (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        // 1: lo que hacían todas hasta ahora.
        $this->addSql('ALTER TABLE activity ADD submission_name_full_path TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('ALTER TABLE activity DROP submission_name_full_path');
    }
}
