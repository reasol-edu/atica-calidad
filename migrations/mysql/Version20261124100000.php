<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Los archivos adjuntos de los campos «Adjuntos» de los documentos rellenados (form_attachment) (MySQL/MariaDB). */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modelos de documento: archivos adjuntos de los documentos rellenados (form_attachment) (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('CREATE TABLE form_attachment (id BINARY(16) NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, uploaded_at DATETIME NOT NULL, submission_id BINARY(16) NOT NULL, file_id BINARY(16) NOT NULL, uploaded_by_id BINARY(16) DEFAULT NULL, INDEX idx_form_attachment_submission (submission_id), INDEX IDX_7098952F93CB796C (file_id), INDEX IDX_7098952FA2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE form_attachment ADD CONSTRAINT FK_7098952FE1FD4933 FOREIGN KEY (submission_id) REFERENCES form_submission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE form_attachment ADD CONSTRAINT FK_7098952F93CB796C FOREIGN KEY (file_id) REFERENCES document_file (id)');
        $this->addSql('ALTER TABLE form_attachment ADD CONSTRAINT FK_7098952FA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('DROP TABLE form_attachment');
    }
}
