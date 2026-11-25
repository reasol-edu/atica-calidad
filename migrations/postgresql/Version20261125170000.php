<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pasos de la cadena de aprobación limitados al elemento del documento (form_approval_step.element_scoped) (PostgreSQL).
 * Los pasos de las cadenas ya empezadas quedan como estaban: los decide cualquiera con el perfil.
 */
final class Version20261125170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasos de aprobación limitados al elemento del documento: form_approval_step.element_scoped (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE form_approval_step ADD element_scoped BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE form_approval_step DROP element_scoped');
    }
}
