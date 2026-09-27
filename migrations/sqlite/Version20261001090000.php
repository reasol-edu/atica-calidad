<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Generador de calendarios: orientación de página y escala de tipografía por calendario (SQLite) */
final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generador de calendarios: orientación de página y escala de tipografía por calendario (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("ALTER TABLE printable_calendar ADD COLUMN orientation VARCHAR(255) NOT NULL DEFAULT 'portrait'");
        $this->addSql('ALTER TABLE printable_calendar ADD COLUMN font_size_scale INTEGER NOT NULL DEFAULT 100');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TEMPORARY TABLE __temp__printable_calendar AS SELECT id, title, description, start_date, end_date, non_working_day_color, weekend_color, created_at, educational_centre_id, academic_year_id, owner_id FROM printable_calendar');
        $this->addSql('DROP TABLE printable_calendar');
        $this->addSql('CREATE TABLE printable_calendar (id BLOB NOT NULL, title VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, non_working_day_color VARCHAR(7) DEFAULT NULL, weekend_color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL, educational_centre_id BLOB NOT NULL, academic_year_id BLOB NOT NULL, owner_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_7A232D2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A232D2FC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A232D2F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO printable_calendar (id, title, description, start_date, end_date, non_working_day_color, weekend_color, created_at, educational_centre_id, academic_year_id, owner_id) SELECT id, title, description, start_date, end_date, non_working_day_color, weekend_color, created_at, educational_centre_id, academic_year_id, owner_id FROM __temp__printable_calendar');
        $this->addSql('DROP TABLE __temp__printable_calendar');
        $this->addSql('CREATE INDEX IDX_7A232D2F61F9EE23 ON printable_calendar (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2FC54F3401 ON printable_calendar (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2F7E3C61F9 ON printable_calendar (owner_id)');
    }
}
