<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elimina las etiquetas de los elementos de lista y de las actividades (tablas tag, list_item_tag y activity_tag) (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE activity_tag');
        $this->addSql('DROP TABLE list_item_tag');
        $this->addSql('DROP TABLE tag');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        // The tables come back empty: the tags themselves are not recoverable.
        $this->addSql('CREATE TABLE tag (id UUID NOT NULL, name VARCHAR(100) NOT NULL, educational_centre_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_389B78361F9EE23 ON tag (educational_centre_id)');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B78361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE list_item_tag (list_item_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY (list_item_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_9AF1FFD1CE208F53 ON list_item_tag (list_item_id)');
        $this->addSql('CREATE INDEX IDX_9AF1FFD1BAD26311 ON list_item_tag (tag_id)');
        $this->addSql('ALTER TABLE list_item_tag ADD CONSTRAINT FK_9AF1FFD1CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_tag ADD CONSTRAINT FK_9AF1FFD1BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE activity_tag (activity_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY (activity_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_71B0290181C06096 ON activity_tag (activity_id)');
        $this->addSql('CREATE INDEX IDX_71B02901BAD26311 ON activity_tag (tag_id)');
        $this->addSql('ALTER TABLE activity_tag ADD CONSTRAINT FK_71B0290181C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_tag ADD CONSTRAINT FK_71B02901BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
    }
}
