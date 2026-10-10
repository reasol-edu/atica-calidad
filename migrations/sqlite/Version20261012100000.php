<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historial de cambios de una actividad: tabla activity_change (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE activity_change (id BLOB NOT NULL, created_at DATETIME NOT NULL, type VARCHAR(20) NOT NULL, changes CLOB NOT NULL, activity_id BLOB NOT NULL, author_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_12211E2081C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_12211E20F675F31B FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_12211E2081C06096 ON activity_change (activity_id)');
        $this->addSql('CREATE INDEX IDX_12211E20F675F31B ON activity_change (author_id)');
        $this->addSql('CREATE INDEX idx_activity_change_activity_created ON activity_change (activity_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE activity_change');
    }
}
