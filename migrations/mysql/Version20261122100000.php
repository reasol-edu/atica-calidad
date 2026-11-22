<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento rellenables (fase 1): form_model, form_model_revision, form_submission y form_submission_revision, y el modo de entrega y el modelo de activity (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('CREATE TABLE form_model (id BINARY(16) NOT NULL, code VARCHAR(40) DEFAULT NULL, draft_definition JSON NOT NULL, draft_version INT NOT NULL, draft_updated_at DATETIME NOT NULL, document_id BINARY(16) NOT NULL, draft_updated_by_id BINARY(16) DEFAULT NULL, UNIQUE INDEX uq_form_model_document (document_id), INDEX IDX_54D2D61A7165E4AC (draft_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE form_model_revision (id BINARY(16) NOT NULL, definition JSON NOT NULL, code VARCHAR(40) DEFAULT NULL, model_id BINARY(16) NOT NULL, revision_id BINARY(16) NOT NULL, UNIQUE INDEX uq_form_model_revision_revision (revision_id), INDEX IDX_117CBF557975B7E7 (model_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE form_submission (id BINARY(16) NOT NULL, slot_key VARCHAR(255) NOT NULL, cycle_year INT NOT NULL, draft_data JSON DEFAULT NULL, draft_version INT NOT NULL, updated_at DATETIME NOT NULL, activity_id BINARY(16) NOT NULL, model_revision_id BINARY(16) NOT NULL, document_id BINARY(16) DEFAULT NULL, updated_by_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_D2C21667C33F7837 (document_id), UNIQUE INDEX uq_form_submission_slot (activity_id, slot_key, cycle_year), INDEX IDX_D2C2166781C06096 (activity_id), INDEX IDX_D2C2166773F84563 (model_revision_id), INDEX IDX_D2C21667896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE form_submission_revision (id BINARY(16) NOT NULL, data JSON NOT NULL, submission_id BINARY(16) NOT NULL, revision_id BINARY(16) NOT NULL, model_revision_id BINARY(16) NOT NULL, UNIQUE INDEX uq_form_submission_revision_revision (revision_id), INDEX IDX_C501B7EBE1FD4933 (submission_id), INDEX IDX_C501B7EB73F84563 (model_revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE form_model ADD CONSTRAINT FK_54D2D61AC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_model ADD CONSTRAINT FK_54D2D61A7165E4AC FOREIGN KEY (draft_updated_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE form_model_revision ADD CONSTRAINT FK_117CBF557975B7E7 FOREIGN KEY (model_id) REFERENCES form_model (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_model_revision ADD CONSTRAINT FK_117CBF551DFA7C8F FOREIGN KEY (revision_id) REFERENCES document_revision (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C2166781C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C2166773F84563 FOREIGN KEY (model_revision_id) REFERENCES form_model_revision (id)');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C21667C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C21667896DBBDE FOREIGN KEY (updated_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE form_submission_revision ADD CONSTRAINT FK_C501B7EBE1FD4933 FOREIGN KEY (submission_id) REFERENCES form_submission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_submission_revision ADD CONSTRAINT FK_C501B7EB1DFA7C8F FOREIGN KEY (revision_id) REFERENCES document_revision (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_submission_revision ADD CONSTRAINT FK_C501B7EB73F84563 FOREIGN KEY (model_revision_id) REFERENCES form_model_revision (id)');

        $this->addSql("ALTER TABLE activity ADD submission_mode VARCHAR(10) DEFAULT 'file' NOT NULL, ADD form_model_id BINARY(16) DEFAULT NULL");
        $this->addSql('ALTER TABLE activity ADD CONSTRAINT FK_AC74095AC84F32A3 FOREIGN KEY (form_model_id) REFERENCES form_model (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_AC74095AC84F32A3 ON activity (form_model_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('ALTER TABLE activity DROP FOREIGN KEY FK_AC74095AC84F32A3');
        $this->addSql('DROP INDEX IDX_AC74095AC84F32A3 ON activity');
        $this->addSql('ALTER TABLE activity DROP form_model_id, DROP submission_mode');
        $this->addSql('DROP TABLE form_submission_revision');
        $this->addSql('DROP TABLE form_submission');
        $this->addSql('DROP TABLE form_model_revision');
        $this->addSql('DROP TABLE form_model');
    }
}
