<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento (fase 4): cadena de aprobación (form_approval_step), comentarios anclados (form_comment), historial por campo (form_field_change) y bloqueo de edición de form_submission (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('CREATE TABLE form_comment (id BINARY(16) NOT NULL, anchor_key VARCHAR(40) NOT NULL, path VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, revision_id BINARY(16) NOT NULL, author_id BINARY(16) DEFAULT NULL, INDEX idx_form_comment_revision (revision_id), INDEX IDX_A5183257F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE form_approval_step (id BINARY(16) NOT NULL, position INT NOT NULL, name VARCHAR(255) NOT NULL, state VARCHAR(10) NOT NULL, decided_at DATETIME DEFAULT NULL, note LONGTEXT DEFAULT NULL, revision_id BINARY(16) NOT NULL, profile_id BINARY(16) DEFAULT NULL, decided_by_id BINARY(16) DEFAULT NULL, UNIQUE INDEX uq_form_approval_step_position (revision_id, position), INDEX IDX_34BFA3EC1DFA7C8F (revision_id), INDEX IDX_34BFA3ECCCFA12B8 (profile_id), INDEX IDX_34BFA3ECE26B496B (decided_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE form_field_change (id BINARY(16) NOT NULL, block_key VARCHAR(40) NOT NULL, path VARCHAR(255) NOT NULL, row_key VARCHAR(40) DEFAULT NULL, column_key VARCHAR(40) DEFAULT NULL, old_value LONGTEXT DEFAULT NULL, new_value LONGTEXT DEFAULT NULL, changed_at DATETIME NOT NULL, submission_id BINARY(16) NOT NULL, changed_by_id BINARY(16) DEFAULT NULL, INDEX idx_form_field_change_block (submission_id, block_key), INDEX IDX_543940E1FD4933 (submission_id), INDEX IDX_543940828AD0A0 (changed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE form_comment ADD CONSTRAINT FK_A51832571DFA7C8F FOREIGN KEY (revision_id) REFERENCES form_submission_revision (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_comment ADD CONSTRAINT FK_A5183257F675F31B FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE form_approval_step ADD CONSTRAINT FK_34BFA3EC1DFA7C8F FOREIGN KEY (revision_id) REFERENCES form_submission_revision (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_approval_step ADD CONSTRAINT FK_34BFA3ECCCFA12B8 FOREIGN KEY (profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE form_approval_step ADD CONSTRAINT FK_34BFA3ECE26B496B FOREIGN KEY (decided_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE form_field_change ADD CONSTRAINT FK_543940E1FD4933 FOREIGN KEY (submission_id) REFERENCES form_submission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_field_change ADD CONSTRAINT FK_543940828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES teacher (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE form_submission ADD locked_until DATETIME DEFAULT NULL, ADD locked_by_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C216677A88E00 FOREIGN KEY (locked_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D2C216677A88E00 ON form_submission (locked_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('ALTER TABLE form_submission DROP FOREIGN KEY FK_D2C216677A88E00');
        $this->addSql('DROP INDEX IDX_D2C216677A88E00 ON form_submission');
        $this->addSql('ALTER TABLE form_submission DROP locked_by_id, DROP locked_until');
        $this->addSql('DROP TABLE form_field_change');
        $this->addSql('DROP TABLE form_approval_step');
        $this->addSql('DROP TABLE form_comment');
    }
}
