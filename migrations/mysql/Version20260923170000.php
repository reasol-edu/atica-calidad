<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Papelera: document y activity.deleted_at / deleted_by_name, y activity.trashed_folder_id (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE document ADD deleted_at DATETIME DEFAULT NULL, ADD deleted_by_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD deleted_at DATETIME DEFAULT NULL, ADD deleted_by_name VARCHAR(255) DEFAULT NULL, ADD trashed_folder_id BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        // What's in the trash would come back as if never deleted: purge it first.
        $this->addSql('DELETE FROM document WHERE deleted_at IS NOT NULL');
        $this->addSql('DELETE FROM activity WHERE deleted_at IS NOT NULL');
        $this->addSql('ALTER TABLE document DROP deleted_at, DROP deleted_by_name');
        $this->addSql('ALTER TABLE activity DROP deleted_at, DROP deleted_by_name, DROP trashed_folder_id');
    }
}
