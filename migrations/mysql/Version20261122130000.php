<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento (fase 6): índice de valores, fuente calculada de indicadores y desglose de mediciones (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('CREATE TABLE form_value (id BINARY(16) NOT NULL, field_key VARCHAR(36) NOT NULL, path VARCHAR(255) NOT NULL, row_key VARCHAR(36) DEFAULT NULL, num DOUBLE PRECISION DEFAULT NULL, text VARCHAR(255) DEFAULT NULL, flag TINYINT DEFAULT NULL, submission_revision_id BINARY(16) NOT NULL, INDEX idx_form_value_field (field_key, submission_revision_id), INDEX IDX_9E30FCF79A3E6395 (submission_revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE indicator_form_source (id BINARY(16) NOT NULL, field_key VARCHAR(36) NOT NULL, aggregation VARCHAR(12) NOT NULL, condition_op VARCHAR(2) DEFAULT NULL, condition_value VARCHAR(100) DEFAULT NULL, denominator_key VARCHAR(36) DEFAULT NULL, group_by VARCHAR(60) DEFAULT \'\' NOT NULL, indicator_id BINARY(16) NOT NULL, activity_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_704E595C4402854A (indicator_id), INDEX IDX_704E595C81C06096 (activity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE measurement_breakdown (id BINARY(16) NOT NULL, group_key VARCHAR(100) NOT NULL, group_label VARCHAR(255) NOT NULL, value DOUBLE PRECISION NOT NULL, count INT NOT NULL, measurement_id BINARY(16) NOT NULL, INDEX IDX_127443C4924EA134 (measurement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE form_value ADD CONSTRAINT FK_9E30FCF79A3E6395 FOREIGN KEY (submission_revision_id) REFERENCES form_submission_revision (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE indicator_form_source ADD CONSTRAINT FK_704E595C4402854A FOREIGN KEY (indicator_id) REFERENCES indicator (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE indicator_form_source ADD CONSTRAINT FK_704E595C81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE measurement_breakdown ADD CONSTRAINT FK_127443C4924EA134 FOREIGN KEY (measurement_id) REFERENCES measurement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE measurement ADD computed TINYINT DEFAULT 0 NOT NULL, ADD sample_count INT DEFAULT NULL, ADD expected_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('ALTER TABLE measurement DROP computed, DROP sample_count, DROP expected_count');
        $this->addSql('DROP TABLE measurement_breakdown');
        $this->addSql('DROP TABLE indicator_form_source');
        $this->addSql('DROP TABLE form_value');
    }
}
