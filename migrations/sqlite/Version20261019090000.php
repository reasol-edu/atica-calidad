<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generador de calendarios: mostrar las horas de cada día pasa de ser un ajuste del calendario a serlo de cada periodo (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE printable_calendar_period ADD COLUMN show_hours BOOLEAN NOT NULL DEFAULT 1');
        $this->addSql('UPDATE printable_calendar_period SET show_hours = (SELECT c.show_hours FROM printable_calendar c WHERE c.id = printable_calendar_period.calendar_id)');
        $this->addSql('ALTER TABLE printable_calendar DROP COLUMN show_hours');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE printable_calendar ADD COLUMN show_hours BOOLEAN NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE printable_calendar_period DROP COLUMN show_hours');
    }
}
