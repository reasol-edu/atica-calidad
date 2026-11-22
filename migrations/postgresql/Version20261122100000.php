<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento rellenables (fase 1): form_model, form_model_revision, form_submission y form_submission_revision, y el modo de entrega y el modelo de activity (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE form_model (id UUID NOT NULL, code VARCHAR(40) DEFAULT NULL, draft_definition JSON NOT NULL, draft_version INT NOT NULL, draft_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, document_id UUID NOT NULL, draft_updated_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_form_model_document ON form_model (document_id)');
        $this->addSql('CREATE INDEX IDX_54D2D61A7165E4AC ON form_model (draft_updated_by_id)');
        $this->addSql('CREATE TABLE form_model_revision (id UUID NOT NULL, definition JSON NOT NULL, code VARCHAR(40) DEFAULT NULL, model_id UUID NOT NULL, revision_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_form_model_revision_revision ON form_model_revision (revision_id)');
        $this->addSql('CREATE INDEX IDX_117CBF557975B7E7 ON form_model_revision (model_id)');
        $this->addSql('CREATE TABLE form_submission (id UUID NOT NULL, slot_key VARCHAR(255) NOT NULL, cycle_year INT NOT NULL, draft_data JSON DEFAULT NULL, draft_version INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, activity_id UUID NOT NULL, model_revision_id UUID NOT NULL, document_id UUID DEFAULT NULL, updated_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2C21667C33F7837 ON form_submission (document_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_form_submission_slot ON form_submission (activity_id, slot_key, cycle_year)');
        $this->addSql('CREATE INDEX IDX_D2C2166781C06096 ON form_submission (activity_id)');
        $this->addSql('CREATE INDEX IDX_D2C2166773F84563 ON form_submission (model_revision_id)');
        $this->addSql('CREATE INDEX IDX_D2C21667896DBBDE ON form_submission (updated_by_id)');
        $this->addSql('CREATE TABLE form_submission_revision (id UUID NOT NULL, data JSON NOT NULL, submission_id UUID NOT NULL, revision_id UUID NOT NULL, model_revision_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_form_submission_revision_revision ON form_submission_revision (revision_id)');
        $this->addSql('CREATE INDEX IDX_C501B7EBE1FD4933 ON form_submission_revision (submission_id)');
        $this->addSql('CREATE INDEX IDX_C501B7EB73F84563 ON form_submission_revision (model_revision_id)');

        $this->addSql('ALTER TABLE form_model ADD CONSTRAINT FK_54D2D61AC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_model ADD CONSTRAINT FK_54D2D61A7165E4AC FOREIGN KEY (draft_updated_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_model_revision ADD CONSTRAINT FK_117CBF557975B7E7 FOREIGN KEY (model_id) REFERENCES form_model (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_model_revision ADD CONSTRAINT FK_117CBF551DFA7C8F FOREIGN KEY (revision_id) REFERENCES document_revision (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C2166781C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C2166773F84563 FOREIGN KEY (model_revision_id) REFERENCES form_model_revision (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C21667C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C21667896DBBDE FOREIGN KEY (updated_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_submission_revision ADD CONSTRAINT FK_C501B7EBE1FD4933 FOREIGN KEY (submission_id) REFERENCES form_submission (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_submission_revision ADD CONSTRAINT FK_C501B7EB1DFA7C8F FOREIGN KEY (revision_id) REFERENCES document_revision (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_submission_revision ADD CONSTRAINT FK_C501B7EB73F84563 FOREIGN KEY (model_revision_id) REFERENCES form_model_revision (id) NOT DEFERRABLE');

        $this->addSql("ALTER TABLE activity ADD submission_mode VARCHAR(10) DEFAULT 'file' NOT NULL");
        $this->addSql('ALTER TABLE activity ADD form_model_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD CONSTRAINT FK_AC74095AC84F32A3 FOREIGN KEY (form_model_id) REFERENCES form_model (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_AC74095AC84F32A3 ON activity (form_model_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE activity DROP CONSTRAINT FK_AC74095AC84F32A3');
        $this->addSql('DROP INDEX IDX_AC74095AC84F32A3');
        $this->addSql('ALTER TABLE activity DROP form_model_id');
        $this->addSql('ALTER TABLE activity DROP submission_mode');
        $this->addSql('DROP TABLE form_submission_revision');
        $this->addSql('DROP TABLE form_submission');
        $this->addSql('DROP TABLE form_model_revision');
        $this->addSql('DROP TABLE form_model');
    }
}
