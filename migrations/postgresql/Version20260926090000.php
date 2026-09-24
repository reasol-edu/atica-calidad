<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indicadores: measurement_calendar, measurement_period, indicator, indicator_target y measurement, su enlace desde finding e improvement_action, y el ajuste quality.measurement_days (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE measurement_calendar (id UUID NOT NULL, name VARCHAR(100) NOT NULL, educational_centre_id UUID NOT NULL, academic_year_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5DE4BCE761F9EE23 ON measurement_calendar (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_5DE4BCE7C54F3401 ON measurement_calendar (academic_year_id)');
        $this->addSql('CREATE TABLE indicator (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, unit VARCHAR(20) DEFAULT NULL, higher_is_better BOOLEAN NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, educational_centre_id UUID NOT NULL, section_id UUID DEFAULT NULL, responsible_teacher_id UUID DEFAULT NULL, responsible_profile_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D1349DB361F9EE23 ON indicator (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_D1349DB3D823E37A ON indicator (section_id)');
        $this->addSql('CREATE INDEX IDX_D1349DB37A20419A ON indicator (responsible_teacher_id)');
        $this->addSql('CREATE INDEX IDX_D1349DB3F75A2D3F ON indicator (responsible_profile_id)');
        $this->addSql('CREATE TABLE measurement_period (id UUID NOT NULL, name VARCHAR(100) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, position INT NOT NULL, calendar_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C17F272CA40A2C8 ON measurement_period (calendar_id)');
        $this->addSql('CREATE TABLE indicator_target (id UUID NOT NULL, target DOUBLE PRECISION DEFAULT NULL, alert_threshold DOUBLE PRECISION DEFAULT NULL, indicator_id UUID NOT NULL, academic_year_id UUID NOT NULL, calendar_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C57058654402854A ON indicator_target (indicator_id)');
        $this->addSql('CREATE INDEX IDX_C5705865C54F3401 ON indicator_target (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_C5705865A40A2C8 ON indicator_target (calendar_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_indicator_target_year ON indicator_target (indicator_id, academic_year_id)');
        $this->addSql('CREATE TABLE measurement (id UUID NOT NULL, value DOUBLE PRECISION NOT NULL, notes TEXT DEFAULT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, review_note TEXT DEFAULT NULL, indicator_id UUID NOT NULL, period_id UUID NOT NULL, recorded_by_id UUID DEFAULT NULL, reviewed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2CE0D8114402854A ON measurement (indicator_id)');
        $this->addSql('CREATE INDEX IDX_2CE0D811EC8B7ADE ON measurement (period_id)');
        $this->addSql('CREATE INDEX IDX_2CE0D811D05A957B ON measurement (recorded_by_id)');
        $this->addSql('CREATE INDEX IDX_2CE0D811FC6B21F1 ON measurement (reviewed_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_measurement_indicator_period ON measurement (indicator_id, period_id)');
        $this->addSql('ALTER TABLE measurement_calendar ADD CONSTRAINT FK_5DE4BCE761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE measurement_calendar ADD CONSTRAINT FK_5DE4BCE7C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator ADD CONSTRAINT FK_D1349DB361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator ADD CONSTRAINT FK_D1349DB3D823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator ADD CONSTRAINT FK_D1349DB37A20419A FOREIGN KEY (responsible_teacher_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator ADD CONSTRAINT FK_D1349DB3F75A2D3F FOREIGN KEY (responsible_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE measurement_period ADD CONSTRAINT FK_C17F272CA40A2C8 FOREIGN KEY (calendar_id) REFERENCES measurement_calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator_target ADD CONSTRAINT FK_C57058654402854A FOREIGN KEY (indicator_id) REFERENCES indicator (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator_target ADD CONSTRAINT FK_C5705865C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator_target ADD CONSTRAINT FK_C5705865A40A2C8 FOREIGN KEY (calendar_id) REFERENCES measurement_calendar (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D8114402854A FOREIGN KEY (indicator_id) REFERENCES indicator (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D811EC8B7ADE FOREIGN KEY (period_id) REFERENCES measurement_period (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D811D05A957B FOREIGN KEY (recorded_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D811FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE finding ADD measurement_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A7191336924EA134 FOREIGN KEY (measurement_id) REFERENCES measurement (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A7191336924EA134 ON finding (measurement_id)');
        $this->addSql('ALTER TABLE improvement_action ADD measurement_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56E924EA134 FOREIGN KEY (measurement_id) REFERENCES measurement (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2B3BD56E924EA134 ON improvement_action (measurement_id)');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'quality.measurement_days', 'integer', '15', TRUE, TRUE, FALSE, 0, 365, NULL, 'settings.category.quality', 12, 20)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'quality.measurement_days')");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key = 'quality.measurement_days'");

        $this->addSql('ALTER TABLE finding DROP CONSTRAINT FK_A7191336924EA134');
        $this->addSql('DROP INDEX IDX_A7191336924EA134');
        $this->addSql('ALTER TABLE finding DROP measurement_id');
        $this->addSql('ALTER TABLE improvement_action DROP CONSTRAINT FK_2B3BD56E924EA134');
        $this->addSql('DROP INDEX IDX_2B3BD56E924EA134');
        $this->addSql('ALTER TABLE improvement_action DROP measurement_id');
        $this->addSql('DROP TABLE measurement');
        $this->addSql('DROP TABLE indicator_target');
        $this->addSql('DROP TABLE measurement_period');
        $this->addSql('DROP TABLE indicator');
        $this->addSql('DROP TABLE measurement_calendar');
    }
}
