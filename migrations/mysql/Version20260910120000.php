<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plazos estrictos de actividad: start/end date enforced y extensión de plazo (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE activity ADD start_date_enforced TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE activity ADD end_date_enforced TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE activity ADD end_date_grace_days INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE activity ALTER start_date_enforced DROP DEFAULT');
        $this->addSql('ALTER TABLE activity ALTER end_date_enforced DROP DEFAULT');
        $this->addSql('ALTER TABLE activity ALTER end_date_grace_days DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE activity DROP start_date_enforced');
        $this->addSql('ALTER TABLE activity DROP end_date_enforced');
        $this->addSql('ALTER TABLE activity DROP end_date_grace_days');
    }
}
