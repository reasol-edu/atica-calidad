<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plazos distintos por elemento de la lista de una actividad: tabla activity_list_item_deadline y activity_completion.leaf_list_item_id (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE activity_list_item_deadline (id BINARY(16) NOT NULL, start_day INT NOT NULL, start_month INT NOT NULL, end_day INT NOT NULL, end_month INT NOT NULL, activity_id BINARY(16) NOT NULL, list_item_id BINARY(16) NOT NULL, INDEX IDX_D193AFB881C06096 (activity_id), INDEX IDX_D193AFB8CE208F53 (list_item_id), UNIQUE INDEX uq_activity_list_item_deadline (activity_id, list_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_list_item_deadline ADD CONSTRAINT FK_D193AFB881C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_list_item_deadline ADD CONSTRAINT FK_D193AFB8CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE activity_completion ADD leaf_list_item_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity_completion ADD CONSTRAINT FK_6D34097678DF6F9F FOREIGN KEY (leaf_list_item_id) REFERENCES list_item (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6D34097678DF6F9F ON activity_completion (leaf_list_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE activity_list_item_deadline');
        $this->addSql('ALTER TABLE activity_completion DROP FOREIGN KEY FK_6D34097678DF6F9F');
        $this->addSql('DROP INDEX IDX_6D34097678DF6F9F ON activity_completion');
        $this->addSql('ALTER TABLE activity_completion DROP leaf_list_item_id');
    }
}
