<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plazos distintos por elemento de la lista de una actividad: tabla activity_list_item_deadline y activity_completion.leaf_list_item_id (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE activity_list_item_deadline (id BLOB NOT NULL, start_day INTEGER NOT NULL, start_month INTEGER NOT NULL, end_day INTEGER NOT NULL, end_month INTEGER NOT NULL, activity_id BLOB NOT NULL, list_item_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_D193AFB881C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D193AFB8CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D193AFB881C06096 ON activity_list_item_deadline (activity_id)');
        $this->addSql('CREATE INDEX IDX_D193AFB8CE208F53 ON activity_list_item_deadline (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_activity_list_item_deadline ON activity_list_item_deadline (activity_id, list_item_id)');

        $this->addSql('ALTER TABLE activity_completion ADD COLUMN leaf_list_item_id BLOB DEFAULT NULL CONSTRAINT FK_6D34097678DF6F9F REFERENCES list_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_6D34097678DF6F9F ON activity_completion (leaf_list_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE activity_list_item_deadline');
        $this->addSql('ALTER TABLE activity_completion DROP COLUMN leaf_list_item_id');
    }
}
