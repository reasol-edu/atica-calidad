<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Motivos de rechazo propios de cada centro: tabla rejection_reason (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE rejection_reason (id UUID NOT NULL, text VARCHAR(255) NOT NULL, position INT NOT NULL, educational_centre_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AD6BE68661F9EE23 ON rejection_reason (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_rejection_reason_centre_position ON rejection_reason (educational_centre_id, position)');
        $this->addSql('ALTER TABLE rejection_reason ADD CONSTRAINT FK_AD6BE68661F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE rejection_reason');
    }
}
