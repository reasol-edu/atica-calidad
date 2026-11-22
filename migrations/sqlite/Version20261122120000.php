<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento (fase 5): política de actualización de form_model_revision, borradores para todos y origen del prerrelleno en activity, y origen del prerrelleno en form_submission (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("ALTER TABLE form_model_revision ADD COLUMN update_policy VARCHAR(10) DEFAULT 'optional' NOT NULL");
        $this->addSql('ALTER TABLE form_model_revision ADD COLUMN update_deadline DATE DEFAULT NULL');
        // ADD COLUMN, not the table rebuild Doctrine's diff proposes: the activity table is referenced by many others.
        $this->addSql('ALTER TABLE activity ADD COLUMN form_drafts_for_all BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE activity ADD COLUMN form_prefill_source_id BLOB DEFAULT NULL REFERENCES activity (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_AC74095A70C155F0 ON activity (form_prefill_source_id)');
        $this->addSql('ALTER TABLE form_submission ADD COLUMN prefilled_from CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE form_submission DROP COLUMN prefilled_from');
        $this->addSql('DROP INDEX IDX_AC74095A70C155F0');
        $this->addSql('ALTER TABLE activity DROP COLUMN form_prefill_source_id');
        $this->addSql('ALTER TABLE activity DROP COLUMN form_drafts_for_all');
        $this->addSql('ALTER TABLE form_model_revision DROP COLUMN update_deadline');
        $this->addSql('ALTER TABLE form_model_revision DROP COLUMN update_policy');
    }
}
