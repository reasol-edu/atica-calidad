<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indicators. finding and improvement_action get their measurement_id with ALTER TABLE (SQLite
 * accepts a REFERENCES column there when it defaults to NULL), not by rebuilding them: with
 * foreign keys on (SqlitePragmasMiddleware), dropping those tables would cascade-delete their
 * actions, evidence and timelines. Going down does need the rebuild, so it switches foreign keys
 * off for it — which SQLite only allows outside a transaction, hence isTransactional().
 */
final class Version20260926090000 extends AbstractMigration
{
    private const string FINDING_COLUMNS = 'id, code, title, description, origin, status, kind, severity, reported_at, classified_at, analysis_due_date, whys, root_cause, verification_due_date, effective, verification_notes, verified_at, discard_reason, closed_at, educational_centre_id, section_id, reported_by_id, classified_by_id, analysis_responsible_id, verified_by_id';

    private const string ACTION_COLUMNS = 'id, type, description, due_date, status, created_at, done_at, result, educational_centre_id, finding_id, responsible_teacher_id, responsible_profile_id, created_by_id, done_by_id, code, goal, academic_year_id, section_id';

    public function getDescription(): string
    {
        return 'Indicadores: measurement_calendar, measurement_period, indicator, indicator_target y measurement, su enlace desde finding e improvement_action, y el ajuste quality.measurement_days (SQLite)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE measurement_calendar (id BLOB NOT NULL, name VARCHAR(100) NOT NULL, educational_centre_id BLOB NOT NULL, academic_year_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_5DE4BCE761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5DE4BCE7C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_5DE4BCE761F9EE23 ON measurement_calendar (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_5DE4BCE7C54F3401 ON measurement_calendar (academic_year_id)');

        $this->addSql('CREATE TABLE indicator (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, unit VARCHAR(20) DEFAULT NULL, higher_is_better BOOLEAN NOT NULL, active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, educational_centre_id BLOB NOT NULL, section_id BLOB DEFAULT NULL, responsible_teacher_id BLOB DEFAULT NULL, responsible_profile_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_D1349DB361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D1349DB3D823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D1349DB37A20419A FOREIGN KEY (responsible_teacher_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D1349DB3F75A2D3F FOREIGN KEY (responsible_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D1349DB361F9EE23 ON indicator (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_D1349DB3D823E37A ON indicator (section_id)');
        $this->addSql('CREATE INDEX IDX_D1349DB37A20419A ON indicator (responsible_teacher_id)');
        $this->addSql('CREATE INDEX IDX_D1349DB3F75A2D3F ON indicator (responsible_profile_id)');

        $this->addSql('CREATE TABLE measurement_period (id BLOB NOT NULL, name VARCHAR(100) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, position INTEGER NOT NULL, calendar_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C17F272CA40A2C8 FOREIGN KEY (calendar_id) REFERENCES measurement_calendar (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C17F272CA40A2C8 ON measurement_period (calendar_id)');

        $this->addSql('CREATE TABLE indicator_target (id BLOB NOT NULL, target DOUBLE PRECISION DEFAULT NULL, alert_threshold DOUBLE PRECISION DEFAULT NULL, indicator_id BLOB NOT NULL, academic_year_id BLOB NOT NULL, calendar_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_C57058654402854A FOREIGN KEY (indicator_id) REFERENCES indicator (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C5705865C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C5705865A40A2C8 FOREIGN KEY (calendar_id) REFERENCES measurement_calendar (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C57058654402854A ON indicator_target (indicator_id)');
        $this->addSql('CREATE INDEX IDX_C5705865C54F3401 ON indicator_target (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_C5705865A40A2C8 ON indicator_target (calendar_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_indicator_target_year ON indicator_target (indicator_id, academic_year_id)');

        $this->addSql('CREATE TABLE measurement (id BLOB NOT NULL, value DOUBLE PRECISION NOT NULL, notes CLOB DEFAULT NULL, recorded_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, review_note CLOB DEFAULT NULL, indicator_id BLOB NOT NULL, period_id BLOB NOT NULL, recorded_by_id BLOB DEFAULT NULL, reviewed_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_2CE0D8114402854A FOREIGN KEY (indicator_id) REFERENCES indicator (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2CE0D811EC8B7ADE FOREIGN KEY (period_id) REFERENCES measurement_period (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2CE0D811D05A957B FOREIGN KEY (recorded_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2CE0D811FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2CE0D8114402854A ON measurement (indicator_id)');
        $this->addSql('CREATE INDEX IDX_2CE0D811EC8B7ADE ON measurement (period_id)');
        $this->addSql('CREATE INDEX IDX_2CE0D811D05A957B ON measurement (recorded_by_id)');
        $this->addSql('CREATE INDEX IDX_2CE0D811FC6B21F1 ON measurement (reviewed_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_measurement_indicator_period ON measurement (indicator_id, period_id)');

        $this->addSql('ALTER TABLE finding ADD COLUMN measurement_id BLOB DEFAULT NULL CONSTRAINT FK_A7191336924EA134 REFERENCES measurement (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_A7191336924EA134 ON finding (measurement_id)');
        $this->addSql('ALTER TABLE improvement_action ADD COLUMN measurement_id BLOB DEFAULT NULL CONSTRAINT FK_2B3BD56E924EA134 REFERENCES measurement (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_2B3BD56E924EA134 ON improvement_action (measurement_id)');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            ('df566f73-adce-48ce-b3ce-4ca07a47b707', 'quality.measurement_days', 'integer', '15', 1, 1, 0, 0, 365, NULL, 'settings.category.quality', 12, 20)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'quality.measurement_days')");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key = 'quality.measurement_days'");

        $this->addSql('PRAGMA foreign_keys = OFF');

        $this->addSql('CREATE TEMPORARY TABLE __temp__finding AS SELECT ' . self::FINDING_COLUMNS . ' FROM finding');
        $this->addSql('DROP TABLE finding');
        $this->addSql('CREATE TABLE finding (id BLOB NOT NULL, code VARCHAR(20) DEFAULT NULL, title VARCHAR(255) NOT NULL, description CLOB NOT NULL, origin VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, kind VARCHAR(255) DEFAULT NULL, severity VARCHAR(255) DEFAULT NULL, reported_at DATETIME NOT NULL, classified_at DATETIME DEFAULT NULL, analysis_due_date DATE DEFAULT NULL, whys CLOB DEFAULT NULL, root_cause CLOB DEFAULT NULL, verification_due_date DATE DEFAULT NULL, effective BOOLEAN DEFAULT NULL, verification_notes CLOB DEFAULT NULL, verified_at DATETIME DEFAULT NULL, discard_reason CLOB DEFAULT NULL, closed_at DATETIME DEFAULT NULL, educational_centre_id BLOB NOT NULL, section_id BLOB DEFAULT NULL, reported_by_id BLOB DEFAULT NULL, classified_by_id BLOB DEFAULT NULL, analysis_responsible_id BLOB DEFAULT NULL, verified_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_A719133661F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A7191336D823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A719133671CE806 FOREIGN KEY (reported_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A7191336CF8EF351 FOREIGN KEY (classified_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A71913366D401C79 FOREIGN KEY (analysis_responsible_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A719133669F4B775 FOREIGN KEY (verified_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO finding (' . self::FINDING_COLUMNS . ') SELECT ' . self::FINDING_COLUMNS . ' FROM __temp__finding');
        $this->addSql('DROP TABLE __temp__finding');
        $this->addSql('CREATE UNIQUE INDEX uq_finding_centre_code ON finding (educational_centre_id, code)');
        $this->addSql('CREATE INDEX idx_finding_centre_status ON finding (educational_centre_id, status)');
        $this->addSql('CREATE INDEX IDX_A719133669F4B775 ON finding (verified_by_id)');
        $this->addSql('CREATE INDEX IDX_A71913366D401C79 ON finding (analysis_responsible_id)');
        $this->addSql('CREATE INDEX IDX_A7191336CF8EF351 ON finding (classified_by_id)');
        $this->addSql('CREATE INDEX IDX_A719133671CE806 ON finding (reported_by_id)');
        $this->addSql('CREATE INDEX IDX_A7191336D823E37A ON finding (section_id)');
        $this->addSql('CREATE INDEX IDX_A719133661F9EE23 ON finding (educational_centre_id)');

        $this->addSql('CREATE TEMPORARY TABLE __temp__improvement_action AS SELECT ' . self::ACTION_COLUMNS . ' FROM improvement_action');
        $this->addSql('DROP TABLE improvement_action');
        $this->addSql('CREATE TABLE improvement_action (id BLOB NOT NULL, type VARCHAR(255) NOT NULL, description CLOB NOT NULL, due_date DATE DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, done_at DATETIME DEFAULT NULL, result CLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, finding_id BLOB DEFAULT NULL, responsible_teacher_id BLOB DEFAULT NULL, responsible_profile_id BLOB DEFAULT NULL, created_by_id BLOB DEFAULT NULL, done_by_id BLOB DEFAULT NULL, code VARCHAR(20) DEFAULT NULL, goal CLOB DEFAULT NULL, academic_year_id BLOB DEFAULT NULL, section_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_2B3BD56EC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56ED823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E4323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E7A20419A FOREIGN KEY (responsible_teacher_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56EF75A2D3F FOREIGN KEY (responsible_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56EB03A8386 FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E35AE3EF9 FOREIGN KEY (done_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO improvement_action (' . self::ACTION_COLUMNS . ') SELECT ' . self::ACTION_COLUMNS . ' FROM __temp__improvement_action');
        $this->addSql('DROP TABLE __temp__improvement_action');
        $this->addSql('CREATE UNIQUE INDEX uq_improvement_action_centre_code ON improvement_action (educational_centre_id, code)');
        $this->addSql('CREATE INDEX IDX_2B3BD56ED823E37A ON improvement_action (section_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EC54F3401 ON improvement_action (academic_year_id)');
        $this->addSql('CREATE INDEX idx_improvement_action_centre_status ON improvement_action (educational_centre_id, status)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E35AE3EF9 ON improvement_action (done_by_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EB03A8386 ON improvement_action (created_by_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EF75A2D3F ON improvement_action (responsible_profile_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E7A20419A ON improvement_action (responsible_teacher_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E4323B5E7 ON improvement_action (finding_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E61F9EE23 ON improvement_action (educational_centre_id)');

        $this->addSql('DROP TABLE measurement');
        $this->addSql('DROP TABLE indicator_target');
        $this->addSql('DROP TABLE measurement_period');
        $this->addSql('DROP TABLE indicator');
        $this->addSql('DROP TABLE measurement_calendar');

        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
