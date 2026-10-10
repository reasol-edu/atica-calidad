<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendario personal suscribible (iCal): tabla calendar_feed_token (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE calendar_feed_token (id BLOB NOT NULL, token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, teacher_id BLOB NOT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3CF2368241807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3CF2368261F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3CF2368241807E1D ON calendar_feed_token (teacher_id)');
        $this->addSql('CREATE INDEX IDX_3CF2368261F9EE23 ON calendar_feed_token (educational_centre_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_feed_token ON calendar_feed_token (token)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_feed_teacher_centre ON calendar_feed_token (teacher_id, educational_centre_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE calendar_feed_token');
    }
}
