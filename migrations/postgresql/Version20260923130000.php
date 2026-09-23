<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Completados y entregas de actividades por curso: activity_completion.cycle_year y document.activity_cycle_year (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        // Existing rows get the academic year their own date falls in, with the default start of
        // the academic year (Sep 15): whatever was done before Sep 15 belongs to the previous one.
        $this->addSql('ALTER TABLE activity_completion ADD cycle_year INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE activity_completion
            SET cycle_year = CAST(EXTRACT(YEAR FROM completed_at) AS INT)
                - CASE WHEN CAST(TO_CHAR(completed_at, 'MMDD') AS INT) < 915 THEN 1 ELSE 0 END
        SQL);
        $this->addSql('ALTER TABLE activity_completion ALTER cycle_year SET NOT NULL');

        $this->addSql('ALTER TABLE document ADD activity_cycle_year INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE document
            SET activity_cycle_year = CAST(EXTRACT(YEAR FROM uploaded_at) AS INT)
                - CASE WHEN CAST(TO_CHAR(uploaded_at, 'MMDD') AS INT) < 915 THEN 1 ELSE 0 END
            WHERE folder_id IN (SELECT folder_id FROM activity WHERE folder_id IS NOT NULL)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE document DROP activity_cycle_year');
        $this->addSql('ALTER TABLE activity_completion DROP cycle_year');
    }
}
