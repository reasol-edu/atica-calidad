<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historial de cambios de una actividad: tabla activity_change (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE activity_change (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, type VARCHAR(20) NOT NULL, changes JSON NOT NULL, activity_id BINARY(16) NOT NULL, author_id BINARY(16) DEFAULT NULL, INDEX IDX_12211E2081C06096 (activity_id), INDEX IDX_12211E20F675F31B (author_id), INDEX idx_activity_change_activity_created (activity_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_change ADD CONSTRAINT FK_12211E2081C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_change ADD CONSTRAINT FK_12211E20F675F31B FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE activity_change');
    }
}
