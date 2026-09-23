<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Papelera: document y activity.deleted_at / deleted_by_name, y activity.trashed_folder_id (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE document ADD COLUMN deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD COLUMN deleted_by_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD COLUMN deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD COLUMN deleted_by_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD COLUMN trashed_folder_id BLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // What's in the trash would come back as if never deleted: purge it first.
        $this->addSql('DELETE FROM document WHERE deleted_at IS NOT NULL');
        $this->addSql('DELETE FROM activity WHERE deleted_at IS NOT NULL');
        $this->addSql('ALTER TABLE document DROP COLUMN deleted_at');
        $this->addSql('ALTER TABLE document DROP COLUMN deleted_by_name');
        $this->addSql('ALTER TABLE activity DROP COLUMN deleted_at');
        $this->addSql('ALTER TABLE activity DROP COLUMN deleted_by_name');
        $this->addSql('ALTER TABLE activity DROP COLUMN trashed_folder_id');
    }
}
