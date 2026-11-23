<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fecha de aprobación de cada revisión de un documento (SQLite). Las revisiones ya aprobadas, que no la guardaron, toman la fecha de la revisión.
 */
final class Version20261123150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revisiones de documento: fecha de aprobación (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE document_revision ADD COLUMN approved_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE document_revision SET approved_at = revised_at WHERE pending_review = 0 AND rejected = 0');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE document_revision DROP COLUMN approved_at');
    }
}
