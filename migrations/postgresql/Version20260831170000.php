<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documentos relacionados de una actividad (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('CREATE TABLE activity_related_document (activity_id UUID NOT NULL, document_id UUID NOT NULL, PRIMARY KEY (activity_id, document_id))');
        $this->addSql('CREATE INDEX IDX_9AA925B981C06096 ON activity_related_document (activity_id)');
        $this->addSql('CREATE INDEX IDX_9AA925B9C33F7837 ON activity_related_document (document_id)');
        $this->addSql('ALTER TABLE activity_related_document ADD CONSTRAINT FK_9AA925B981C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_related_document ADD CONSTRAINT FK_9AA925B9C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE activity_related_document DROP CONSTRAINT FK_9AA925B981C06096');
        $this->addSql('ALTER TABLE activity_related_document DROP CONSTRAINT FK_9AA925B9C33F7837');
        $this->addSql('DROP TABLE activity_related_document');
    }
}
