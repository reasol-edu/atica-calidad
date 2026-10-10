<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendario personal suscribible (iCal): tabla calendar_feed_token (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE calendar_feed_token (id BINARY(16) NOT NULL, token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, teacher_id BINARY(16) NOT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_3CF2368241807E1D (teacher_id), INDEX IDX_3CF2368261F9EE23 (educational_centre_id), UNIQUE INDEX uniq_calendar_feed_token (token), UNIQUE INDEX uniq_calendar_feed_teacher_centre (teacher_id, educational_centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE calendar_feed_token ADD CONSTRAINT FK_3CF2368241807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_feed_token ADD CONSTRAINT FK_3CF2368261F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE calendar_feed_token');
    }
}
