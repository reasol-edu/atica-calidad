<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generador de calendarios: mostrar las horas de cada día pasa de ser un ajuste del calendario a serlo de cada periodo (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE printable_calendar_period ADD show_hours TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE printable_calendar_period p SET p.show_hours = (SELECT c.show_hours FROM printable_calendar c WHERE c.id = p.calendar_id)');
        $this->addSql('ALTER TABLE printable_calendar DROP show_hours');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE printable_calendar ADD show_hours TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE printable_calendar_period DROP show_hours');
    }
}
