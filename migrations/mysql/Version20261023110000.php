<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elimina las etiquetas de los elementos de lista y de las actividades (tablas tag, list_item_tag y activity_tag) (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE activity_tag');
        $this->addSql('DROP TABLE list_item_tag');
        $this->addSql('DROP TABLE tag');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        // The tables come back empty: the tags themselves are not recoverable.
        $this->addSql('CREATE TABLE tag (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_389B78361F9EE23 (educational_centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B78361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE list_item_tag (list_item_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_9AF1FFD1CE208F53 (list_item_id), INDEX IDX_9AF1FFD1BAD26311 (tag_id), PRIMARY KEY (list_item_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE list_item_tag ADD CONSTRAINT FK_9AF1FFD1CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_tag ADD CONSTRAINT FK_9AF1FFD1BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE activity_tag (activity_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_71B0290181C06096 (activity_id), INDEX IDX_71B02901BAD26311 (tag_id), PRIMARY KEY (activity_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_tag ADD CONSTRAINT FK_71B0290181C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_tag ADD CONSTRAINT FK_71B02901BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
    }
}
