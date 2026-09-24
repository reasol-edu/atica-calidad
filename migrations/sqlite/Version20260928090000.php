<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Management review. improvement_action gets its management_review_id with ALTER TABLE, as in
 * Version20260926090000 and for the same reason: rebuilding it with foreign keys on would
 * cascade-delete its attachments. Going down rebuilds it with foreign keys off, which SQLite only
 * allows outside a transaction — hence isTransactional().
 */
final class Version20260928090000 extends AbstractMigration
{
    private const string ACTION_COLUMNS = 'id, type, description, due_date, status, created_at, done_at, result, educational_centre_id, finding_id, responsible_teacher_id, responsible_profile_id, created_by_id, done_by_id, code, goal, academic_year_id, section_id, measurement_id';

    public function getDescription(): string
    {
        return 'Revisión por la dirección: management_review y su enlace desde improvement_action (SQLite)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE management_review (id BLOB NOT NULL, title VARCHAR(255) NOT NULL, held_on DATE NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, attendees CLOB DEFAULT NULL, context_changes CLOB DEFAULT NULL, satisfaction CLOB DEFAULT NULL, suppliers CLOB DEFAULT NULL, resources CLOB DEFAULT NULL, conclusions CLOB DEFAULT NULL, snapshot CLOB DEFAULT NULL, created_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, educational_centre_id BLOB NOT NULL, academic_year_id BLOB NOT NULL, created_by_id BLOB DEFAULT NULL, closed_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_4F5A850C61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4F5A850CC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4F5A850CB03A8386 FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4F5A850CE1FA7797 FOREIGN KEY (closed_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4F5A850C61F9EE23 ON management_review (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_4F5A850CC54F3401 ON management_review (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_4F5A850CB03A8386 ON management_review (created_by_id)');
        $this->addSql('CREATE INDEX IDX_4F5A850CE1FA7797 ON management_review (closed_by_id)');

        $this->addSql('ALTER TABLE improvement_action ADD COLUMN management_review_id BLOB DEFAULT NULL CONSTRAINT FK_2B3BD56EA211E8FF REFERENCES management_review (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_2B3BD56EA211E8FF ON improvement_action (management_review_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('PRAGMA foreign_keys = OFF');

        $this->addSql('CREATE TEMPORARY TABLE __temp__improvement_action AS SELECT ' . self::ACTION_COLUMNS . ' FROM improvement_action');
        $this->addSql('DROP TABLE improvement_action');
        $this->addSql('CREATE TABLE improvement_action (id BLOB NOT NULL, type VARCHAR(255) NOT NULL, description CLOB NOT NULL, due_date DATE DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, done_at DATETIME DEFAULT NULL, result CLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, finding_id BLOB DEFAULT NULL, responsible_teacher_id BLOB DEFAULT NULL, responsible_profile_id BLOB DEFAULT NULL, created_by_id BLOB DEFAULT NULL, done_by_id BLOB DEFAULT NULL, code VARCHAR(20) DEFAULT NULL, goal CLOB DEFAULT NULL, academic_year_id BLOB DEFAULT NULL CONSTRAINT FK_2B3BD56EC54F3401 REFERENCES academic_year (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, section_id BLOB DEFAULT NULL CONSTRAINT FK_2B3BD56ED823E37A REFERENCES document_section (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, measurement_id BLOB DEFAULT NULL CONSTRAINT FK_2B3BD56E924EA134 REFERENCES measurement (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, PRIMARY KEY (id), CONSTRAINT FK_2B3BD56E61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E4323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E7A20419A FOREIGN KEY (responsible_teacher_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56EF75A2D3F FOREIGN KEY (responsible_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56EB03A8386 FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E35AE3EF9 FOREIGN KEY (done_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO improvement_action (' . self::ACTION_COLUMNS . ') SELECT ' . self::ACTION_COLUMNS . ' FROM __temp__improvement_action');
        $this->addSql('DROP TABLE __temp__improvement_action');
        $this->addSql('CREATE INDEX IDX_2B3BD56E61F9EE23 ON improvement_action (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E4323B5E7 ON improvement_action (finding_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E7A20419A ON improvement_action (responsible_teacher_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EF75A2D3F ON improvement_action (responsible_profile_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EB03A8386 ON improvement_action (created_by_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E35AE3EF9 ON improvement_action (done_by_id)');
        $this->addSql('CREATE INDEX idx_improvement_action_centre_status ON improvement_action (educational_centre_id, status)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EC54F3401 ON improvement_action (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56ED823E37A ON improvement_action (section_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_improvement_action_centre_code ON improvement_action (educational_centre_id, code)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E924EA134 ON improvement_action (measurement_id)');

        $this->addSql('DROP TABLE management_review');

        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
