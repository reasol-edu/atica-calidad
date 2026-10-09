<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registro de los recordatorios manuales de una actividad: tabla activity_reminder (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE activity_reminder (id BLOB NOT NULL, sent_at DATETIME NOT NULL, message CLOB DEFAULT NULL, delivered BOOLEAN NOT NULL, activity_id BLOB NOT NULL, recipient_id BLOB NOT NULL, sent_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_E03DFF4E81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E03DFF4EE92F8F78 FOREIGN KEY (recipient_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E03DFF4EA45BB98C FOREIGN KEY (sent_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E03DFF4E81C06096 ON activity_reminder (activity_id)');
        $this->addSql('CREATE INDEX IDX_E03DFF4EE92F8F78 ON activity_reminder (recipient_id)');
        $this->addSql('CREATE INDEX IDX_E03DFF4EA45BB98C ON activity_reminder (sent_by_id)');
        $this->addSql('CREATE INDEX idx_activity_reminder_activity_sent ON activity_reminder (activity_id, sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE activity_reminder');
    }
}
