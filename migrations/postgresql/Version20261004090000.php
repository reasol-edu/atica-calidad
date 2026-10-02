<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Perfiles responsables de una actividad manual: la tabla activity_responsible_profile (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE activity_responsible_profile (id UUID NOT NULL, activity_id UUID NOT NULL, specific_profile_id UUID NOT NULL, list_item_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7877AD2F81C06096 ON activity_responsible_profile (activity_id)');
        $this->addSql('CREATE INDEX IDX_7877AD2FDF5533E ON activity_responsible_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_7877AD2FCE208F53 ON activity_responsible_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_activity_responsible_profile ON activity_responsible_profile (activity_id, specific_profile_id, list_item_id)');
        $this->addSql('ALTER TABLE activity_responsible_profile ADD CONSTRAINT FK_7877AD2F81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_responsible_profile ADD CONSTRAINT FK_7877AD2FDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_responsible_profile ADD CONSTRAINT FK_7877AD2FCE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE activity_responsible_profile');
    }
}
