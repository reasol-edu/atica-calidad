<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento (fase 6): índice de valores, fuente calculada de indicadores y desglose de mediciones (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE form_value (id UUID NOT NULL, field_key VARCHAR(36) NOT NULL, path VARCHAR(255) NOT NULL, row_key VARCHAR(36) DEFAULT NULL, num DOUBLE PRECISION DEFAULT NULL, text VARCHAR(1000) DEFAULT NULL, flag BOOLEAN DEFAULT NULL, submission_revision_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_form_value_field ON form_value (field_key, submission_revision_id)');
        $this->addSql('CREATE INDEX IDX_9E30FCF79A3E6395 ON form_value (submission_revision_id)');
        $this->addSql('CREATE TABLE indicator_form_source (id UUID NOT NULL, field_key VARCHAR(36) NOT NULL, aggregation VARCHAR(12) NOT NULL, condition_op VARCHAR(2) DEFAULT NULL, condition_value VARCHAR(100) DEFAULT NULL, denominator_key VARCHAR(36) DEFAULT NULL, group_by VARCHAR(60) DEFAULT \'\' NOT NULL, indicator_id UUID NOT NULL, activity_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_704E595C4402854A ON indicator_form_source (indicator_id)');
        $this->addSql('CREATE INDEX IDX_704E595C81C06096 ON indicator_form_source (activity_id)');
        $this->addSql('CREATE TABLE measurement_breakdown (id UUID NOT NULL, group_key VARCHAR(100) NOT NULL, group_label VARCHAR(255) NOT NULL, value DOUBLE PRECISION NOT NULL, count INT NOT NULL, measurement_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_127443C4924EA134 ON measurement_breakdown (measurement_id)');
        $this->addSql('ALTER TABLE form_value ADD CONSTRAINT FK_9E30FCF79A3E6395 FOREIGN KEY (submission_revision_id) REFERENCES form_submission_revision (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator_form_source ADD CONSTRAINT FK_704E595C4402854A FOREIGN KEY (indicator_id) REFERENCES indicator (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indicator_form_source ADD CONSTRAINT FK_704E595C81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE measurement_breakdown ADD CONSTRAINT FK_127443C4924EA134 FOREIGN KEY (measurement_id) REFERENCES measurement (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE measurement ADD computed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE measurement ADD sample_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE measurement ADD expected_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE measurement DROP COLUMN expected_count');
        $this->addSql('ALTER TABLE measurement DROP COLUMN sample_count');
        $this->addSql('ALTER TABLE measurement DROP COLUMN computed');
        $this->addSql('DROP TABLE measurement_breakdown');
        $this->addSql('DROP TABLE indicator_form_source');
        $this->addSql('DROP TABLE form_value');
    }
}
