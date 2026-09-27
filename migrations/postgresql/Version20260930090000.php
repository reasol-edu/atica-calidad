<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generador de calendarios (Utilidades): printable_calendar, printable_calendar_date y printable_calendar_period (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE printable_calendar (id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, non_working_day_color VARCHAR(7) DEFAULT NULL, weekend_color VARCHAR(7) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, educational_centre_id UUID NOT NULL, academic_year_id UUID NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7A232D2F61F9EE23 ON printable_calendar (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2FC54F3401 ON printable_calendar (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2F7E3C61F9 ON printable_calendar (owner_id)');
        $this->addSql('CREATE TABLE printable_calendar_date (id UUID NOT NULL, date DATE NOT NULL, color VARCHAR(7) NOT NULL, description VARCHAR(255) NOT NULL, calendar_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9A9E192A40A2C8 ON printable_calendar_date (calendar_id)');
        $this->addSql('CREATE TABLE printable_calendar_period (id UUID NOT NULL, description VARCHAR(255) NOT NULL, color VARCHAR(7) NOT NULL, show_journey_summary BOOLEAN NOT NULL, mode VARCHAR(255) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, total_hours DOUBLE PRECISION DEFAULT NULL, monday_hours DOUBLE PRECISION DEFAULT NULL, tuesday_hours DOUBLE PRECISION DEFAULT NULL, wednesday_hours DOUBLE PRECISION DEFAULT NULL, thursday_hours DOUBLE PRECISION DEFAULT NULL, friday_hours DOUBLE PRECISION DEFAULT NULL, position INT NOT NULL, calendar_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3CAFB3FCA40A2C8 ON printable_calendar_period (calendar_id)');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2FC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar_date ADD CONSTRAINT FK_9A9E192A40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar_period ADD CONSTRAINT FK_3CAFB3FCA40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE printable_calendar DROP CONSTRAINT FK_7A232D2F61F9EE23');
        $this->addSql('ALTER TABLE printable_calendar DROP CONSTRAINT FK_7A232D2FC54F3401');
        $this->addSql('ALTER TABLE printable_calendar DROP CONSTRAINT FK_7A232D2F7E3C61F9');
        $this->addSql('ALTER TABLE printable_calendar_date DROP CONSTRAINT FK_9A9E192A40A2C8');
        $this->addSql('ALTER TABLE printable_calendar_period DROP CONSTRAINT FK_3CAFB3FCA40A2C8');
        $this->addSql('DROP TABLE printable_calendar_period');
        $this->addSql('DROP TABLE printable_calendar_date');
        $this->addSql('DROP TABLE printable_calendar');
    }
}
