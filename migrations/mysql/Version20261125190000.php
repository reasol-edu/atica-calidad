<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La actividad de la que salen las filas de cada tabla de entregas de un modelo («Datos de otras entregas») (MySQL/MariaDB).
 */
final class Version20261125190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Datos de otras entregas: tabla activity_delivery_source (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('CREATE TABLE activity_delivery_source (id BINARY(16) NOT NULL, block_key VARCHAR(36) NOT NULL, activity_id BINARY(16) NOT NULL, source_activity_id BINARY(16) NOT NULL, UNIQUE INDEX uq_activity_delivery_source (activity_id, block_key), INDEX IDX_CB5EF7181C06096 (activity_id), INDEX IDX_CB5EF71BDBF3036 (source_activity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_delivery_source ADD CONSTRAINT FK_CB5EF7181C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_delivery_source ADD CONSTRAINT FK_CB5EF71BDBF3036 FOREIGN KEY (source_activity_id) REFERENCES activity (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('DROP TABLE activity_delivery_source');
    }
}
