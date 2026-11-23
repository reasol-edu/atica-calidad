<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fecha de aprobación de cada revisión de un documento (PostgreSQL). Las revisiones ya aprobadas, que no la guardaron, toman la fecha de la revisión.
 */
final class Version20261123150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revisiones de documento: fecha de aprobación (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE document_revision ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE document_revision SET approved_at = revised_at WHERE pending_review = FALSE AND rejected = FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE document_revision DROP COLUMN approved_at');
    }
}
