<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revisión por la dirección: management_review y su enlace desde improvement_action (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE management_review (id UUID NOT NULL, title VARCHAR(255) NOT NULL, held_on DATE NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, attendees TEXT DEFAULT NULL, context_changes TEXT DEFAULT NULL, satisfaction TEXT DEFAULT NULL, suppliers TEXT DEFAULT NULL, resources TEXT DEFAULT NULL, conclusions TEXT DEFAULT NULL, snapshot JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, educational_centre_id UUID NOT NULL, academic_year_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, closed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4F5A850C61F9EE23 ON management_review (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_4F5A850CC54F3401 ON management_review (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_4F5A850CB03A8386 ON management_review (created_by_id)');
        $this->addSql('CREATE INDEX IDX_4F5A850CE1FA7797 ON management_review (closed_by_id)');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850C61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850CC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850CB03A8386 FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850CE1FA7797 FOREIGN KEY (closed_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('ALTER TABLE improvement_action ADD management_review_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56EA211E8FF FOREIGN KEY (management_review_id) REFERENCES management_review (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2B3BD56EA211E8FF ON improvement_action (management_review_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE improvement_action DROP CONSTRAINT FK_2B3BD56EA211E8FF');
        $this->addSql('DROP INDEX IDX_2B3BD56EA211E8FF');
        $this->addSql('ALTER TABLE improvement_action DROP management_review_id');
        $this->addSql('DROP TABLE management_review');
    }
}
