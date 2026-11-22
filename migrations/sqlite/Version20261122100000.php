<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento rellenables (fase 1): form_model, form_model_revision, form_submission y form_submission_revision, y el modo de entrega y el modelo de activity (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE form_model (id BLOB NOT NULL, code VARCHAR(40) DEFAULT NULL, draft_definition CLOB NOT NULL, draft_version INTEGER NOT NULL, draft_updated_at DATETIME NOT NULL, document_id BLOB NOT NULL, draft_updated_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_54D2D61AC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_54D2D61A7165E4AC FOREIGN KEY (draft_updated_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uq_form_model_document ON form_model (document_id)');
        $this->addSql('CREATE INDEX IDX_54D2D61A7165E4AC ON form_model (draft_updated_by_id)');
        $this->addSql('CREATE TABLE form_model_revision (id BLOB NOT NULL, definition CLOB NOT NULL, code VARCHAR(40) DEFAULT NULL, model_id BLOB NOT NULL, revision_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_117CBF557975B7E7 FOREIGN KEY (model_id) REFERENCES form_model (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_117CBF551DFA7C8F FOREIGN KEY (revision_id) REFERENCES document_revision (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uq_form_model_revision_revision ON form_model_revision (revision_id)');
        $this->addSql('CREATE INDEX IDX_117CBF557975B7E7 ON form_model_revision (model_id)');
        $this->addSql('CREATE TABLE form_submission (id BLOB NOT NULL, slot_key VARCHAR(255) NOT NULL, cycle_year INTEGER NOT NULL, draft_data CLOB DEFAULT NULL, draft_version INTEGER NOT NULL, updated_at DATETIME NOT NULL, activity_id BLOB NOT NULL, model_revision_id BLOB NOT NULL, document_id BLOB DEFAULT NULL, updated_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_D2C2166781C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D2C2166773F84563 FOREIGN KEY (model_revision_id) REFERENCES form_model_revision (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D2C21667C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D2C21667896DBBDE FOREIGN KEY (updated_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2C21667C33F7837 ON form_submission (document_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_form_submission_slot ON form_submission (activity_id, slot_key, cycle_year)');
        $this->addSql('CREATE INDEX IDX_D2C2166781C06096 ON form_submission (activity_id)');
        $this->addSql('CREATE INDEX IDX_D2C2166773F84563 ON form_submission (model_revision_id)');
        $this->addSql('CREATE INDEX IDX_D2C21667896DBBDE ON form_submission (updated_by_id)');
        $this->addSql('CREATE TABLE form_submission_revision (id BLOB NOT NULL, data CLOB NOT NULL, submission_id BLOB NOT NULL, revision_id BLOB NOT NULL, model_revision_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C501B7EBE1FD4933 FOREIGN KEY (submission_id) REFERENCES form_submission (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C501B7EB1DFA7C8F FOREIGN KEY (revision_id) REFERENCES document_revision (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C501B7EB73F84563 FOREIGN KEY (model_revision_id) REFERENCES form_model_revision (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uq_form_submission_revision_revision ON form_submission_revision (revision_id)');
        $this->addSql('CREATE INDEX IDX_C501B7EBE1FD4933 ON form_submission_revision (submission_id)');
        $this->addSql('CREATE INDEX IDX_C501B7EB73F84563 ON form_submission_revision (model_revision_id)');

        // ADD COLUMN, not the table rebuild Doctrine's diff proposes: the activity table is referenced by many others.
        $this->addSql("ALTER TABLE activity ADD COLUMN submission_mode VARCHAR(10) DEFAULT 'file' NOT NULL");
        $this->addSql('ALTER TABLE activity ADD COLUMN form_model_id BLOB DEFAULT NULL REFERENCES form_model (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_AC74095AC84F32A3 ON activity (form_model_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP INDEX IDX_AC74095AC84F32A3');
        $this->addSql('ALTER TABLE activity DROP COLUMN form_model_id');
        $this->addSql('ALTER TABLE activity DROP COLUMN submission_mode');
        $this->addSql('DROP TABLE form_submission_revision');
        $this->addSql('DROP TABLE form_submission');
        $this->addSql('DROP TABLE form_model_revision');
        $this->addSql('DROP TABLE form_model');
    }
}
