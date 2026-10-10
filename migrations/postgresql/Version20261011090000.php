<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendario personal suscribible (iCal): tabla calendar_feed_token (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE calendar_feed_token (id UUID NOT NULL, token VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, teacher_id UUID NOT NULL, educational_centre_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3CF2368241807E1D ON calendar_feed_token (teacher_id)');
        $this->addSql('CREATE INDEX IDX_3CF2368261F9EE23 ON calendar_feed_token (educational_centre_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_feed_token ON calendar_feed_token (token)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_feed_teacher_centre ON calendar_feed_token (teacher_id, educational_centre_id)');
        $this->addSql('ALTER TABLE calendar_feed_token ADD CONSTRAINT FK_3CF2368241807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_feed_token ADD CONSTRAINT FK_3CF2368261F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE calendar_feed_token');
    }
}
