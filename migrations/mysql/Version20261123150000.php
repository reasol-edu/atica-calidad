<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fecha de aprobación de cada revisión de un documento (MySQL y MariaDB). Las revisiones ya aprobadas, que no la guardaron, toman la fecha de la revisión.
 */
final class Version20261123150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revisiones de documento: fecha de aprobación (MySQL y MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE document_revision ADD approved_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE document_revision SET approved_at = revised_at WHERE pending_review = 0 AND rejected = 0');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE document_revision DROP COLUMN approved_at');
    }
}
