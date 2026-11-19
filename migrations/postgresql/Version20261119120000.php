<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índice de texto de los ficheros guardados (búsqueda dentro de los documentos): una fila por fichero con su texto legible y
 * el mismo texto sin tildes ni mayúsculas, que rellena el worker (PostgreSQL)
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla document_file_text: texto extraído de cada fichero para buscar dentro de los documentos (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE document_file_text (id UUID NOT NULL, status VARCHAR(20) NOT NULL, extractor_version INT NOT NULL, text TEXT DEFAULT NULL, folded TEXT DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, indexed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, file_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_document_file_text_status ON document_file_text (status)');
        $this->addSql('CREATE UNIQUE INDEX uq_document_file_text_file ON document_file_text (file_id)');
        $this->addSql('ALTER TABLE document_file_text ADD CONSTRAINT FK_A04FE70893CB796C FOREIGN KEY (file_id) REFERENCES document_file (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE document_file_text');
    }
}
