<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Los archivos adjuntos de los campos «Adjuntos» de los documentos rellenados (form_attachment) (SQLite). */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento: archivos adjuntos de los documentos rellenados (form_attachment) (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE form_attachment (id BLOB NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, uploaded_at DATETIME NOT NULL, submission_id BLOB NOT NULL, file_id BLOB NOT NULL, uploaded_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_7098952FE1FD4933 FOREIGN KEY (submission_id) REFERENCES form_submission (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7098952F93CB796C FOREIGN KEY (file_id) REFERENCES document_file (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7098952FA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_form_attachment_submission ON form_attachment (submission_id)');
        $this->addSql('CREATE INDEX IDX_7098952F93CB796C ON form_attachment (file_id)');
        $this->addSql('CREATE INDEX IDX_7098952FA2B28FE8 ON form_attachment (uploaded_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE form_attachment');
    }
}
