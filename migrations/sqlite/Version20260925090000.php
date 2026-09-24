<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the columns with ALTER TABLE rather than by rebuilding the table: with foreign keys on
 * (SqlitePragmasMiddleware), dropping improvement_action would cascade-delete its evidence in
 * quality_attachment. SQLite accepts a REFERENCES column in ALTER TABLE ADD as long as it
 * defaults to NULL. Going down does need the rebuild, so it saves and restores that evidence.
 */
final class Version20260925090000 extends AbstractMigration
{
    private const string COLUMNS = 'id, type, description, due_date, status, created_at, done_at, result, educational_centre_id, finding_id, responsible_teacher_id, responsible_profile_id, created_by_id, done_by_id';

    private const string ATTACHMENT_COLUMNS = 'id, filename, uploaded_at, finding_id, action_id, file_id, uploaded_by_id';

    public function getDescription(): string
    {
        return 'Plan de mejora: código, curso, proceso y objetivo de las acciones de mejora (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE improvement_action ADD COLUMN code VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE improvement_action ADD COLUMN goal CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE improvement_action ADD COLUMN academic_year_id BLOB DEFAULT NULL CONSTRAINT FK_2B3BD56EC54F3401 REFERENCES academic_year (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE improvement_action ADD COLUMN section_id BLOB DEFAULT NULL CONSTRAINT FK_2B3BD56ED823E37A REFERENCES document_section (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_2B3BD56EC54F3401 ON improvement_action (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56ED823E37A ON improvement_action (section_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_improvement_action_centre_code ON improvement_action (educational_centre_id, code)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TEMPORARY TABLE __temp__action_evidence AS SELECT ' . self::ATTACHMENT_COLUMNS . ' FROM quality_attachment WHERE action_id IS NOT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__improvement_action AS SELECT ' . self::COLUMNS . ' FROM improvement_action');
        $this->addSql('DROP TABLE improvement_action');
        $this->addSql('CREATE TABLE improvement_action (id BLOB NOT NULL, type VARCHAR(255) NOT NULL, description CLOB NOT NULL, due_date DATE DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, done_at DATETIME DEFAULT NULL, result CLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, finding_id BLOB DEFAULT NULL, responsible_teacher_id BLOB DEFAULT NULL, responsible_profile_id BLOB DEFAULT NULL, created_by_id BLOB DEFAULT NULL, done_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_2B3BD56E61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E4323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E7A20419A FOREIGN KEY (responsible_teacher_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56EF75A2D3F FOREIGN KEY (responsible_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56EB03A8386 FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E35AE3EF9 FOREIGN KEY (done_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO improvement_action (' . self::COLUMNS . ') SELECT ' . self::COLUMNS . ' FROM __temp__improvement_action');
        $this->addSql('DROP TABLE __temp__improvement_action');
        $this->addSql('CREATE INDEX idx_improvement_action_centre_status ON improvement_action (educational_centre_id, status)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E35AE3EF9 ON improvement_action (done_by_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EB03A8386 ON improvement_action (created_by_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EF75A2D3F ON improvement_action (responsible_profile_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E7A20419A ON improvement_action (responsible_teacher_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E4323B5E7 ON improvement_action (finding_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E61F9EE23 ON improvement_action (educational_centre_id)');
        $this->addSql('INSERT OR IGNORE INTO quality_attachment (' . self::ATTACHMENT_COLUMNS . ') SELECT ' . self::ATTACHMENT_COLUMNS . ' FROM __temp__action_evidence');
        $this->addSql('DROP TABLE __temp__action_evidence');
    }
}
