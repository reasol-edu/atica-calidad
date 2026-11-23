<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Los archivos adjuntos de los campos «Adjuntos» de los documentos rellenados (form_attachment) (PostgreSQL). */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento: archivos adjuntos de los documentos rellenados (form_attachment) (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE form_attachment (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, submission_id UUID NOT NULL, file_id UUID NOT NULL, uploaded_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_form_attachment_submission ON form_attachment (submission_id)');
        $this->addSql('CREATE INDEX IDX_7098952F93CB796C ON form_attachment (file_id)');
        $this->addSql('CREATE INDEX IDX_7098952FA2B28FE8 ON form_attachment (uploaded_by_id)');
        $this->addSql('ALTER TABLE form_attachment ADD CONSTRAINT FK_7098952FE1FD4933 FOREIGN KEY (submission_id) REFERENCES form_submission (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_attachment ADD CONSTRAINT FK_7098952F93CB796C FOREIGN KEY (file_id) REFERENCES document_file (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE form_attachment ADD CONSTRAINT FK_7098952FA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE form_attachment');
    }
}
