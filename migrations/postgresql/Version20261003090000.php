<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restringir actividades manuales a perfiles/subperfiles: activity.general y la tabla activity_profile (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE activity ADD general BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('CREATE TABLE activity_profile (id UUID NOT NULL, activity_id UUID NOT NULL, specific_profile_id UUID NOT NULL, list_item_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8105DCEF81C06096 ON activity_profile (activity_id)');
        $this->addSql('CREATE INDEX IDX_8105DCEFDF5533E ON activity_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_8105DCEFCE208F53 ON activity_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_activity_profile ON activity_profile (activity_id, specific_profile_id, list_item_id)');
        $this->addSql('ALTER TABLE activity_profile ADD CONSTRAINT FK_8105DCEF81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_profile ADD CONSTRAINT FK_8105DCEFDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_profile ADD CONSTRAINT FK_8105DCEFCE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('DROP TABLE activity_profile');
        $this->addSql('ALTER TABLE activity DROP general');
    }
}
