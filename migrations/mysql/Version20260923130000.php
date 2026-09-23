<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Completados y entregas de actividades por curso: activity_completion.cycle_year y document.activity_cycle_year (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        // Existing rows get the academic year their own date falls in, with the default start of
        // the academic year (Sep 15): whatever was done before Sep 15 belongs to the previous one.
        $this->addSql('ALTER TABLE activity_completion ADD cycle_year INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE activity_completion
            SET cycle_year = YEAR(completed_at) - CASE WHEN MONTH(completed_at) * 100 + DAY(completed_at) < 915 THEN 1 ELSE 0 END
        SQL);
        $this->addSql('ALTER TABLE activity_completion MODIFY cycle_year INT NOT NULL');

        $this->addSql('ALTER TABLE document ADD activity_cycle_year INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE document
            SET activity_cycle_year = YEAR(uploaded_at) - CASE WHEN MONTH(uploaded_at) * 100 + DAY(uploaded_at) < 915 THEN 1 ELSE 0 END
            WHERE folder_id IN (SELECT folder_id FROM activity WHERE folder_id IS NOT NULL)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE document DROP activity_cycle_year');
        $this->addSql('ALTER TABLE activity_completion DROP cycle_year');
    }
}
