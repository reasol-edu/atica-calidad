<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generador de calendarios (Utilidades): printable_calendar, printable_calendar_date y
 * printable_calendar_period (SQLite)
 */
final class Version20260930090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generador de calendarios (Utilidades): printable_calendar, printable_calendar_date y printable_calendar_period (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE printable_calendar (id BLOB NOT NULL, title VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, non_working_day_color VARCHAR(7) DEFAULT NULL, weekend_color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL, educational_centre_id BLOB NOT NULL, academic_year_id BLOB NOT NULL, owner_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_7A232D2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A232D2FC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A232D2F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7A232D2F61F9EE23 ON printable_calendar (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2FC54F3401 ON printable_calendar (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2F7E3C61F9 ON printable_calendar (owner_id)');
        $this->addSql('CREATE TABLE printable_calendar_date (id BLOB NOT NULL, date DATE NOT NULL, color VARCHAR(7) NOT NULL, description VARCHAR(255) NOT NULL, calendar_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_9A9E192A40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9A9E192A40A2C8 ON printable_calendar_date (calendar_id)');
        $this->addSql('CREATE TABLE printable_calendar_period (id BLOB NOT NULL, description VARCHAR(255) NOT NULL, color VARCHAR(7) NOT NULL, show_journey_summary BOOLEAN NOT NULL, mode VARCHAR(255) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, total_hours DOUBLE PRECISION DEFAULT NULL, monday_hours DOUBLE PRECISION DEFAULT NULL, tuesday_hours DOUBLE PRECISION DEFAULT NULL, wednesday_hours DOUBLE PRECISION DEFAULT NULL, thursday_hours DOUBLE PRECISION DEFAULT NULL, friday_hours DOUBLE PRECISION DEFAULT NULL, position INTEGER NOT NULL, calendar_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3CAFB3FCA40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3CAFB3FCA40A2C8 ON printable_calendar_period (calendar_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE printable_calendar_period');
        $this->addSql('DROP TABLE printable_calendar_date');
        $this->addSql('DROP TABLE printable_calendar');
    }
}
