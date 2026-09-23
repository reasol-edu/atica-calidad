<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923130000 extends AbstractMigration
{
    private const string COMPLETION_TABLE = 'CREATE TABLE activity_completion (id BLOB NOT NULL, completed_at DATETIME NOT NULL, activity_id BLOB NOT NULL, teacher_id BLOB DEFAULT NULL, profile_id BLOB DEFAULT NULL, list_item_id BLOB DEFAULT NULL, completed_by_id BLOB NOT NULL%s, PRIMARY KEY (id), CONSTRAINT FK_6D34097681C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6D34097641807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6D340976CCFA12B8 FOREIGN KEY (profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6D340976CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6D34097685ECDE76 FOREIGN KEY (completed_by_id) REFERENCES teacher (id) NOT DEFERRABLE INITIALLY IMMEDIATE)';

    private const string COMPLETION_COLUMNS = 'id, completed_at, activity_id, teacher_id, profile_id, list_item_id, completed_by_id';

    public function getDescription(): string
    {
        return 'Completados y entregas de actividades por curso: activity_completion.cycle_year y document.activity_cycle_year (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // SQLite can't ADD a NOT NULL column without a permanent DEFAULT (which Doctrine's schema
        // comparison would then flag as drift), so activity_completion is rebuilt. Existing rows
        // get the academic year their own date falls in, with the default start of the academic
        // year (Sep 15): whatever was done before Sep 15 belongs to the previous one.
        $this->addSql('CREATE TEMPORARY TABLE __temp__activity_completion AS SELECT ' . self::COMPLETION_COLUMNS . ' FROM activity_completion');
        $this->addSql('DROP TABLE activity_completion');
        $this->addSql(\sprintf(self::COMPLETION_TABLE, ', cycle_year INTEGER NOT NULL'));
        $this->addSql(<<<'SQL'
            INSERT INTO activity_completion (id, completed_at, activity_id, teacher_id, profile_id, list_item_id, completed_by_id, cycle_year)
            SELECT id, completed_at, activity_id, teacher_id, profile_id, list_item_id, completed_by_id,
                   CAST(strftime('%Y', completed_at) AS INTEGER) - CASE WHEN CAST(strftime('%m%d', completed_at) AS INTEGER) < 915 THEN 1 ELSE 0 END
            FROM __temp__activity_completion
        SQL);
        $this->addSql('DROP TABLE __temp__activity_completion');
        $this->createCompletionIndexes();

        $this->addSql('ALTER TABLE document ADD COLUMN activity_cycle_year INTEGER DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE document
            SET activity_cycle_year = CAST(strftime('%Y', uploaded_at) AS INTEGER) - CASE WHEN CAST(strftime('%m%d', uploaded_at) AS INTEGER) < 915 THEN 1 ELSE 0 END
            WHERE folder_id IN (SELECT folder_id FROM activity WHERE folder_id IS NOT NULL)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE document DROP COLUMN activity_cycle_year');

        $this->addSql('CREATE TEMPORARY TABLE __temp__activity_completion AS SELECT ' . self::COMPLETION_COLUMNS . ' FROM activity_completion');
        $this->addSql('DROP TABLE activity_completion');
        $this->addSql(\sprintf(self::COMPLETION_TABLE, ''));
        $this->addSql('INSERT INTO activity_completion (' . self::COMPLETION_COLUMNS . ') SELECT ' . self::COMPLETION_COLUMNS . ' FROM __temp__activity_completion');
        $this->addSql('DROP TABLE __temp__activity_completion');
        $this->createCompletionIndexes();
    }

    private function createCompletionIndexes(): void
    {
        $this->addSql('CREATE INDEX IDX_6D34097681C06096 ON activity_completion (activity_id)');
        $this->addSql('CREATE INDEX IDX_6D34097641807E1D ON activity_completion (teacher_id)');
        $this->addSql('CREATE INDEX IDX_6D340976CCFA12B8 ON activity_completion (profile_id)');
        $this->addSql('CREATE INDEX IDX_6D340976CE208F53 ON activity_completion (list_item_id)');
        $this->addSql('CREATE INDEX IDX_6D34097685ECDE76 ON activity_completion (completed_by_id)');
    }
}
