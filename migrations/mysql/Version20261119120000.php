<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índice de texto de los ficheros guardados (búsqueda dentro de los documentos): una fila por fichero con su texto legible y
 * el mismo texto sin tildes ni mayúsculas, que rellena el worker (MySQL / MariaDB). Las columnas de texto son MEDIUMTEXT: un
 * TEXT de MySQL admite 64 KB, menos que un solo informe
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla document_file_text: texto extraído de cada fichero para buscar dentro de los documentos (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL / MariaDB.');

        $this->addSql('CREATE TABLE document_file_text (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, extractor_version INT NOT NULL, text MEDIUMTEXT DEFAULT NULL, folded MEDIUMTEXT DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, indexed_at DATETIME NOT NULL, file_id BINARY(16) NOT NULL, INDEX idx_document_file_text_status (status), UNIQUE INDEX uq_document_file_text_file (file_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document_file_text ADD CONSTRAINT FK_A04FE70893CB796C FOREIGN KEY (file_id) REFERENCES document_file (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL / MariaDB.');

        $this->addSql('DROP TABLE document_file_text');
    }
}
