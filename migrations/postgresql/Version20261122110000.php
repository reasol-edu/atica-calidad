<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento (fase 4): cadena de aprobación (form_approval_step), comentarios anclados (form_comment), historial por campo (form_field_change) y bloqueo de edición de form_submission (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE form_comment (id UUID NOT NULL, anchor_key VARCHAR(40) NOT NULL, path VARCHAR(255) NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revision_id UUID NOT NULL, author_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_form_comment_revision ON form_comment (revision_id)');
        $this->addSql('CREATE INDEX IDX_A5183257F675F31B ON form_comment (author_id)');
        $this->addSql('CREATE TABLE form_approval_step (id UUID NOT NULL, position INT NOT NULL, name VARCHAR(255) NOT NULL, state VARCHAR(10) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, note TEXT DEFAULT NULL, revision_id UUID NOT NULL, profile_id UUID DEFAULT NULL, decided_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_form_approval_step_position ON form_approval_step (revision_id, position)');
        $this->addSql('CREATE INDEX IDX_34BFA3EC1DFA7C8F ON form_approval_step (revision_id)');
        $this->addSql('CREATE INDEX IDX_34BFA3ECCCFA12B8 ON form_approval_step (profile_id)');
        $this->addSql('CREATE INDEX IDX_34BFA3ECE26B496B ON form_approval_step (decided_by_id)');
        $this->addSql('CREATE TABLE form_field_change (id UUID NOT NULL, block_key VARCHAR(40) NOT NULL, path VARCHAR(255) NOT NULL, row_key VARCHAR(40) DEFAULT NULL, column_key VARCHAR(40) DEFAULT NULL, old_value TEXT DEFAULT NULL, new_value TEXT DEFAULT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, submission_id UUID NOT NULL, changed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_form_field_change_block ON form_field_change (submission_id, block_key)');
        $this->addSql('CREATE INDEX IDX_543940E1FD4933 ON form_field_change (submission_id)');
        $this->addSql('CREATE INDEX IDX_543940828AD0A0 ON form_field_change (changed_by_id)');

        $this->addSql('ALTER TABLE form_comment ADD CONSTRAINT FK_A51832571DFA7C8F FOREIGN KEY (revision_id) REFERENCES form_submission_revision (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_comment ADD CONSTRAINT FK_A5183257F675F31B FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_approval_step ADD CONSTRAINT FK_34BFA3EC1DFA7C8F FOREIGN KEY (revision_id) REFERENCES form_submission_revision (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_approval_step ADD CONSTRAINT FK_34BFA3ECCCFA12B8 FOREIGN KEY (profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_approval_step ADD CONSTRAINT FK_34BFA3ECE26B496B FOREIGN KEY (decided_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_field_change ADD CONSTRAINT FK_543940E1FD4933 FOREIGN KEY (submission_id) REFERENCES form_submission (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_field_change ADD CONSTRAINT FK_543940828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('ALTER TABLE form_submission ADD locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE form_submission ADD locked_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C216677A88E00 FOREIGN KEY (locked_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D2C216677A88E00 ON form_submission (locked_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE form_submission DROP CONSTRAINT FK_D2C216677A88E00');
        $this->addSql('DROP INDEX IDX_D2C216677A88E00');
        $this->addSql('ALTER TABLE form_submission DROP locked_by_id');
        $this->addSql('ALTER TABLE form_submission DROP locked_until');
        $this->addSql('DROP TABLE form_field_change');
        $this->addSql('DROP TABLE form_approval_step');
        $this->addSql('DROP TABLE form_comment');
    }
}
