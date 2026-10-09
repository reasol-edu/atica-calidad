<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registro de los recordatorios manuales de una actividad: tabla activity_reminder (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE activity_reminder (id BINARY(16) NOT NULL, sent_at DATETIME NOT NULL, message LONGTEXT DEFAULT NULL, delivered TINYINT NOT NULL, activity_id BINARY(16) NOT NULL, recipient_id BINARY(16) NOT NULL, sent_by_id BINARY(16) DEFAULT NULL, INDEX IDX_E03DFF4E81C06096 (activity_id), INDEX IDX_E03DFF4EE92F8F78 (recipient_id), INDEX IDX_E03DFF4EA45BB98C (sent_by_id), INDEX idx_activity_reminder_activity_sent (activity_id, sent_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_reminder ADD CONSTRAINT FK_E03DFF4E81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_reminder ADD CONSTRAINT FK_E03DFF4EE92F8F78 FOREIGN KEY (recipient_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_reminder ADD CONSTRAINT FK_E03DFF4EA45BB98C FOREIGN KEY (sent_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE activity_reminder');
    }
}
