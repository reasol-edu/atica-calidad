<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La actividad de la que salen las filas de cada tabla de entregas de un modelo («Datos de otras entregas») (PostgreSQL).
 */
final class Version20261125190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Datos de otras entregas: tabla activity_delivery_source (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE activity_delivery_source (id UUID NOT NULL, block_key VARCHAR(36) NOT NULL, activity_id UUID NOT NULL, source_activity_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_activity_delivery_source ON activity_delivery_source (activity_id, block_key)');
        $this->addSql('CREATE INDEX IDX_CB5EF7181C06096 ON activity_delivery_source (activity_id)');
        $this->addSql('CREATE INDEX IDX_CB5EF71BDBF3036 ON activity_delivery_source (source_activity_id)');
        $this->addSql('ALTER TABLE activity_delivery_source ADD CONSTRAINT FK_CB5EF7181C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_delivery_source ADD CONSTRAINT FK_CB5EF71BDBF3036 FOREIGN KEY (source_activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE activity_delivery_source');
    }
}
