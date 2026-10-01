<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restringir actividades manuales a perfiles/subperfiles: activity.general y la tabla activity_profile (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE activity ADD general TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE TABLE activity_profile (id BINARY(16) NOT NULL, activity_id BINARY(16) NOT NULL, specific_profile_id BINARY(16) NOT NULL, list_item_id BINARY(16) DEFAULT NULL, INDEX IDX_8105DCEF81C06096 (activity_id), INDEX IDX_8105DCEFDF5533E (specific_profile_id), INDEX IDX_8105DCEFCE208F53 (list_item_id), UNIQUE INDEX uq_activity_profile (activity_id, specific_profile_id, list_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_profile ADD CONSTRAINT FK_8105DCEF81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_profile ADD CONSTRAINT FK_8105DCEFDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_profile ADD CONSTRAINT FK_8105DCEFCE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE activity_profile');
        $this->addSql('ALTER TABLE activity DROP general');
    }
}
