<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Papelera: document y activity.deleted_at / deleted_by_name, y activity.trashed_folder_id (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE document ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD deleted_by_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD deleted_by_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD trashed_folder_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        // What's in the trash would come back as if never deleted: purge it first.
        $this->addSql('DELETE FROM document WHERE deleted_at IS NOT NULL');
        $this->addSql('DELETE FROM activity WHERE deleted_at IS NOT NULL');
        $this->addSql('ALTER TABLE document DROP deleted_at');
        $this->addSql('ALTER TABLE document DROP deleted_by_name');
        $this->addSql('ALTER TABLE activity DROP deleted_at');
        $this->addSql('ALTER TABLE activity DROP deleted_by_name');
        $this->addSql('ALTER TABLE activity DROP trashed_folder_id');
    }
}
