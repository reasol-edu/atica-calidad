<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pasos de la cadena de aprobación limitados al elemento del documento (form_approval_step.element_scoped) (SQLite).
 * Los pasos de las cadenas ya empezadas quedan como estaban: los decide cualquiera con el perfil.
 */
final class Version20261125170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasos de aprobación limitados al elemento del documento: form_approval_step.element_scoped (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // ADD COLUMN, not the table rebuild Doctrine's diff proposes.
        $this->addSql('ALTER TABLE form_approval_step ADD COLUMN element_scoped BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE form_approval_step DROP COLUMN element_scoped');
    }
}
