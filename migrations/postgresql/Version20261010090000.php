<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registro de los recordatorios manuales de una actividad: tabla activity_reminder (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE activity_reminder (id UUID NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message TEXT DEFAULT NULL, delivered BOOLEAN NOT NULL, activity_id UUID NOT NULL, recipient_id UUID NOT NULL, sent_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E03DFF4E81C06096 ON activity_reminder (activity_id)');
        $this->addSql('CREATE INDEX IDX_E03DFF4EE92F8F78 ON activity_reminder (recipient_id)');
        $this->addSql('CREATE INDEX IDX_E03DFF4EA45BB98C ON activity_reminder (sent_by_id)');
        $this->addSql('CREATE INDEX idx_activity_reminder_activity_sent ON activity_reminder (activity_id, sent_at)');
        $this->addSql('ALTER TABLE activity_reminder ADD CONSTRAINT FK_E03DFF4E81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_reminder ADD CONSTRAINT FK_E03DFF4EE92F8F78 FOREIGN KEY (recipient_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_reminder ADD CONSTRAINT FK_E03DFF4EA45BB98C FOREIGN KEY (sent_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE activity_reminder');
    }
}
