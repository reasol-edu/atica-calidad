<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Acuse de lectura: folder.requires_read_acknowledgement y la tabla document_read_acknowledgement (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE document_read_acknowledgement (id BINARY(16) NOT NULL, acknowledged_at DATETIME NOT NULL, revision_id BINARY(16) NOT NULL, teacher_id BINARY(16) NOT NULL, INDEX IDX_104FC6F31DFA7C8F (revision_id), INDEX IDX_104FC6F341807E1D (teacher_id), UNIQUE INDEX uq_document_read_ack_revision_teacher (revision_id, teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document_read_acknowledgement ADD CONSTRAINT FK_104FC6F31DFA7C8F FOREIGN KEY (revision_id) REFERENCES document_revision (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_read_acknowledgement ADD CONSTRAINT FK_104FC6F341807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE folder ADD requires_read_acknowledgement TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE document_read_acknowledgement');
        $this->addSql('ALTER TABLE folder DROP requires_read_acknowledgement');
    }
}
