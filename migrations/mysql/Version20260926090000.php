<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indicadores: measurement_calendar, measurement_period, indicator, indicator_target y measurement, su enlace desde finding e improvement_action, y el ajuste quality.measurement_days (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE measurement_calendar (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, educational_centre_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, INDEX IDX_5DE4BCE761F9EE23 (educational_centre_id), INDEX IDX_5DE4BCE7C54F3401 (academic_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE indicator (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, unit VARCHAR(20) DEFAULT NULL, higher_is_better TINYINT NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, educational_centre_id BINARY(16) NOT NULL, section_id BINARY(16) DEFAULT NULL, responsible_teacher_id BINARY(16) DEFAULT NULL, responsible_profile_id BINARY(16) DEFAULT NULL, INDEX IDX_D1349DB361F9EE23 (educational_centre_id), INDEX IDX_D1349DB3D823E37A (section_id), INDEX IDX_D1349DB37A20419A (responsible_teacher_id), INDEX IDX_D1349DB3F75A2D3F (responsible_profile_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE measurement_period (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, position INT NOT NULL, calendar_id BINARY(16) NOT NULL, INDEX IDX_C17F272CA40A2C8 (calendar_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE indicator_target (id BINARY(16) NOT NULL, target DOUBLE PRECISION DEFAULT NULL, alert_threshold DOUBLE PRECISION DEFAULT NULL, indicator_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, calendar_id BINARY(16) DEFAULT NULL, INDEX IDX_C57058654402854A (indicator_id), INDEX IDX_C5705865C54F3401 (academic_year_id), INDEX IDX_C5705865A40A2C8 (calendar_id), UNIQUE INDEX uq_indicator_target_year (indicator_id, academic_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE measurement (id BINARY(16) NOT NULL, value DOUBLE PRECISION NOT NULL, notes LONGTEXT DEFAULT NULL, recorded_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, review_note LONGTEXT DEFAULT NULL, indicator_id BINARY(16) NOT NULL, period_id BINARY(16) NOT NULL, recorded_by_id BINARY(16) DEFAULT NULL, reviewed_by_id BINARY(16) DEFAULT NULL, INDEX IDX_2CE0D8114402854A (indicator_id), INDEX IDX_2CE0D811EC8B7ADE (period_id), INDEX IDX_2CE0D811D05A957B (recorded_by_id), INDEX IDX_2CE0D811FC6B21F1 (reviewed_by_id), UNIQUE INDEX uq_measurement_indicator_period (indicator_id, period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE measurement_calendar ADD CONSTRAINT FK_5DE4BCE761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE measurement_calendar ADD CONSTRAINT FK_5DE4BCE7C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE indicator ADD CONSTRAINT FK_D1349DB361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE indicator ADD CONSTRAINT FK_D1349DB3D823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE indicator ADD CONSTRAINT FK_D1349DB37A20419A FOREIGN KEY (responsible_teacher_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE indicator ADD CONSTRAINT FK_D1349DB3F75A2D3F FOREIGN KEY (responsible_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE measurement_period ADD CONSTRAINT FK_C17F272CA40A2C8 FOREIGN KEY (calendar_id) REFERENCES measurement_calendar (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE indicator_target ADD CONSTRAINT FK_C57058654402854A FOREIGN KEY (indicator_id) REFERENCES indicator (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE indicator_target ADD CONSTRAINT FK_C5705865C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE indicator_target ADD CONSTRAINT FK_C5705865A40A2C8 FOREIGN KEY (calendar_id) REFERENCES measurement_calendar (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D8114402854A FOREIGN KEY (indicator_id) REFERENCES indicator (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D811EC8B7ADE FOREIGN KEY (period_id) REFERENCES measurement_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D811D05A957B FOREIGN KEY (recorded_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D811FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE finding ADD measurement_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A7191336924EA134 FOREIGN KEY (measurement_id) REFERENCES measurement (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A7191336924EA134 ON finding (measurement_id)');
        $this->addSql('ALTER TABLE improvement_action ADD measurement_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56E924EA134 FOREIGN KEY (measurement_id) REFERENCES measurement (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2B3BD56E924EA134 ON improvement_action (measurement_id)');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'quality.measurement_days', 'integer', '15', 1, 1, 0, 0, 365, NULL, 'settings.category.quality', 12, 20)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` = 'quality.measurement_days')");
        }
        $this->addSql("DELETE FROM setting_definition WHERE `key` = 'quality.measurement_days'");

        $this->addSql('ALTER TABLE finding DROP FOREIGN KEY FK_A7191336924EA134');
        $this->addSql('DROP INDEX IDX_A7191336924EA134 ON finding');
        $this->addSql('ALTER TABLE finding DROP measurement_id');
        $this->addSql('ALTER TABLE improvement_action DROP FOREIGN KEY FK_2B3BD56E924EA134');
        $this->addSql('DROP INDEX IDX_2B3BD56E924EA134 ON improvement_action');
        $this->addSql('ALTER TABLE improvement_action DROP measurement_id');
        $this->addSql('ALTER TABLE measurement DROP FOREIGN KEY FK_2CE0D8114402854A');
        $this->addSql('ALTER TABLE measurement DROP FOREIGN KEY FK_2CE0D811EC8B7ADE');
        $this->addSql('ALTER TABLE indicator_target DROP FOREIGN KEY FK_C57058654402854A');
        $this->addSql('ALTER TABLE indicator_target DROP FOREIGN KEY FK_C5705865A40A2C8');
        $this->addSql('ALTER TABLE measurement_period DROP FOREIGN KEY FK_C17F272CA40A2C8');
        $this->addSql('DROP TABLE measurement');
        $this->addSql('DROP TABLE indicator_target');
        $this->addSql('DROP TABLE measurement_period');
        $this->addSql('DROP TABLE indicator');
        $this->addSql('DROP TABLE measurement_calendar');
    }
}
