<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Motivos de rechazo propios de cada centro: tabla rejection_reason (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE rejection_reason (id BINARY(16) NOT NULL, text VARCHAR(255) NOT NULL, position INT NOT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_AD6BE68661F9EE23 (educational_centre_id), INDEX idx_rejection_reason_centre_position (educational_centre_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE rejection_reason ADD CONSTRAINT FK_AD6BE68661F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE rejection_reason');
    }
}
