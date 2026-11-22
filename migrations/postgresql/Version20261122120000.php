<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento (fase 5): política de actualización de form_model_revision, borradores para todos y origen del prerrelleno en activity, y origen del prerrelleno en form_submission (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql("ALTER TABLE form_model_revision ADD update_policy VARCHAR(10) DEFAULT 'optional' NOT NULL");
        $this->addSql('ALTER TABLE form_model_revision ADD update_deadline DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD form_drafts_for_all BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE activity ADD form_prefill_source_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD CONSTRAINT FK_AC74095A70C155F0 FOREIGN KEY (form_prefill_source_id) REFERENCES activity (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_AC74095A70C155F0 ON activity (form_prefill_source_id)');
        $this->addSql('ALTER TABLE form_submission ADD prefilled_from JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE form_submission DROP prefilled_from');
        $this->addSql('ALTER TABLE activity DROP CONSTRAINT FK_AC74095A70C155F0');
        $this->addSql('DROP INDEX IDX_AC74095A70C155F0');
        $this->addSql('ALTER TABLE activity DROP form_prefill_source_id');
        $this->addSql('ALTER TABLE activity DROP form_drafts_for_all');
        $this->addSql('ALTER TABLE form_model_revision DROP update_deadline');
        $this->addSql('ALTER TABLE form_model_revision DROP update_policy');
    }
}
