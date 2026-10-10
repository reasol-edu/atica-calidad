<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historial de cambios de una actividad: tabla activity_change (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE activity_change (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, type VARCHAR(20) NOT NULL, changes JSON NOT NULL, activity_id UUID NOT NULL, author_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_12211E2081C06096 ON activity_change (activity_id)');
        $this->addSql('CREATE INDEX IDX_12211E20F675F31B ON activity_change (author_id)');
        $this->addSql('CREATE INDEX idx_activity_change_activity_created ON activity_change (activity_id, created_at)');
        $this->addSql('ALTER TABLE activity_change ADD CONSTRAINT FK_12211E2081C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_change ADD CONSTRAINT FK_12211E20F675F31B FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE activity_change');
    }
}
