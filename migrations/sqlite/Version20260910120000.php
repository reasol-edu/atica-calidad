<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plazos estrictos de actividad: start/end date enforced y extensión de plazo (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // SQLite can't ADD a NOT NULL column without a permanent DEFAULT, which Doctrine's schema
        // comparison then flags as drift — so rebuild the table with the three new columns (0 for
        // every existing row).
        $this->addSql('CREATE TEMPORARY TABLE __temp__activity AS SELECT id, title, description, start_day, start_month, end_day, end_month, required, auto_complete, submission_scope, position, category_id, folder_id, list_item_id FROM activity');
        $this->addSql('DROP TABLE activity');
        $this->addSql('CREATE TABLE activity (id BLOB NOT NULL, title VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, start_day INTEGER NOT NULL, start_month INTEGER NOT NULL, end_day INTEGER NOT NULL, end_month INTEGER NOT NULL, required BOOLEAN NOT NULL, auto_complete BOOLEAN NOT NULL, submission_scope VARCHAR(255) NOT NULL, position INTEGER NOT NULL, category_id BLOB NOT NULL, folder_id BLOB DEFAULT NULL, list_item_id BLOB DEFAULT NULL, start_date_enforced BOOLEAN NOT NULL, end_date_enforced BOOLEAN NOT NULL, end_date_grace_days INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_AC74095A12469DE2 FOREIGN KEY (category_id) REFERENCES activity_category (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095A162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095ACE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO activity (id, title, description, start_day, start_month, end_day, end_month, required, auto_complete, submission_scope, position, category_id, folder_id, list_item_id, start_date_enforced, end_date_enforced, end_date_grace_days) SELECT id, title, description, start_day, start_month, end_day, end_month, required, auto_complete, submission_scope, position, category_id, folder_id, list_item_id, 0, 0, 0 FROM __temp__activity');
        $this->addSql('DROP TABLE __temp__activity');
        $this->addSql('CREATE INDEX IDX_AC74095ACE208F53 ON activity (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AC74095A162CB942 ON activity (folder_id)');
        $this->addSql('CREATE INDEX IDX_AC74095A12469DE2 ON activity (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TEMPORARY TABLE __temp__activity AS SELECT id, title, description, start_day, start_month, end_day, end_month, required, auto_complete, submission_scope, position, category_id, folder_id, list_item_id FROM activity');
        $this->addSql('DROP TABLE activity');
        $this->addSql('CREATE TABLE activity (id BLOB NOT NULL, title VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, start_day INTEGER NOT NULL, start_month INTEGER NOT NULL, end_day INTEGER NOT NULL, end_month INTEGER NOT NULL, required BOOLEAN NOT NULL, auto_complete BOOLEAN NOT NULL, submission_scope VARCHAR(255) NOT NULL, position INTEGER NOT NULL, category_id BLOB NOT NULL, folder_id BLOB DEFAULT NULL, list_item_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_AC74095A12469DE2 FOREIGN KEY (category_id) REFERENCES activity_category (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095A162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC74095ACE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO activity (id, title, description, start_day, start_month, end_day, end_month, required, auto_complete, submission_scope, position, category_id, folder_id, list_item_id) SELECT id, title, description, start_day, start_month, end_day, end_month, required, auto_complete, submission_scope, position, category_id, folder_id, list_item_id FROM __temp__activity');
        $this->addSql('DROP TABLE __temp__activity');
        $this->addSql('CREATE INDEX IDX_AC74095ACE208F53 ON activity (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AC74095A162CB942 ON activity (folder_id)');
        $this->addSql('CREATE INDEX IDX_AC74095A12469DE2 ON activity (category_id)');
    }
}
