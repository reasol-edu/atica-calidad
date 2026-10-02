<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plazos distintos por elemento de la lista de una actividad: tabla activity_list_item_deadline y activity_completion.leaf_list_item_id (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE activity_list_item_deadline (id UUID NOT NULL, start_day INT NOT NULL, start_month INT NOT NULL, end_day INT NOT NULL, end_month INT NOT NULL, activity_id UUID NOT NULL, list_item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D193AFB881C06096 ON activity_list_item_deadline (activity_id)');
        $this->addSql('CREATE INDEX IDX_D193AFB8CE208F53 ON activity_list_item_deadline (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_activity_list_item_deadline ON activity_list_item_deadline (activity_id, list_item_id)');
        $this->addSql('ALTER TABLE activity_list_item_deadline ADD CONSTRAINT FK_D193AFB881C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_list_item_deadline ADD CONSTRAINT FK_D193AFB8CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE activity_completion ADD leaf_list_item_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_6D34097678DF6F9F ON activity_completion (leaf_list_item_id)');
        $this->addSql('ALTER TABLE activity_completion ADD CONSTRAINT FK_6D34097678DF6F9F FOREIGN KEY (leaf_list_item_id) REFERENCES list_item (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE activity_list_item_deadline');
        $this->addSql('ALTER TABLE activity_completion DROP CONSTRAINT FK_6D34097678DF6F9F');
        $this->addSql('DROP INDEX IDX_6D34097678DF6F9F');
        $this->addSql('ALTER TABLE activity_completion DROP leaf_list_item_id');
    }
}
