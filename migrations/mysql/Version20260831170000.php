<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documentos relacionados de una actividad (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('CREATE TABLE activity_related_document (activity_id BINARY(16) NOT NULL, document_id BINARY(16) NOT NULL, INDEX IDX_9AA925B981C06096 (activity_id), INDEX IDX_9AA925B9C33F7837 (document_id), PRIMARY KEY (activity_id, document_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_related_document ADD CONSTRAINT FK_9AA925B981C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_related_document ADD CONSTRAINT FK_9AA925B9C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE activity_related_document DROP FOREIGN KEY FK_9AA925B981C06096');
        $this->addSql('ALTER TABLE activity_related_document DROP FOREIGN KEY FK_9AA925B9C33F7837');
        $this->addSql('DROP TABLE activity_related_document');
    }
}
