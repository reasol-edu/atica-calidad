<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Acuse de lectura: folder.requires_read_acknowledgement y la tabla document_read_acknowledgement (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE document_read_acknowledgement (id BLOB NOT NULL, acknowledged_at DATETIME NOT NULL, revision_id BLOB NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_104FC6F31DFA7C8F FOREIGN KEY (revision_id) REFERENCES document_revision (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_104FC6F341807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_104FC6F31DFA7C8F ON document_read_acknowledgement (revision_id)');
        $this->addSql('CREATE INDEX IDX_104FC6F341807E1D ON document_read_acknowledgement (teacher_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_document_read_ack_revision_teacher ON document_read_acknowledgement (revision_id, teacher_id)');
        $this->addSql('ALTER TABLE folder ADD COLUMN requires_read_acknowledgement BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE document_read_acknowledgement');
        $this->addSql('ALTER TABLE folder DROP COLUMN requires_read_acknowledgement');
    }
}
