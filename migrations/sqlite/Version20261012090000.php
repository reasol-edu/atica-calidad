<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Motivos de rechazo propios de cada centro: tabla rejection_reason (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE rejection_reason (id BLOB NOT NULL, text VARCHAR(255) NOT NULL, position INTEGER NOT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_AD6BE68661F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_AD6BE68661F9EE23 ON rejection_reason (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_rejection_reason_centre_position ON rejection_reason (educational_centre_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE rejection_reason');
    }
}
