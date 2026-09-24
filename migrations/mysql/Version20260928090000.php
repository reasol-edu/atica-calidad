<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revisión por la dirección: management_review y su enlace desde improvement_action (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE management_review (id BINARY(16) NOT NULL, title VARCHAR(255) NOT NULL, held_on DATE NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, attendees LONGTEXT DEFAULT NULL, context_changes LONGTEXT DEFAULT NULL, satisfaction LONGTEXT DEFAULT NULL, suppliers LONGTEXT DEFAULT NULL, resources LONGTEXT DEFAULT NULL, conclusions LONGTEXT DEFAULT NULL, snapshot JSON DEFAULT NULL, created_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, educational_centre_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, created_by_id BINARY(16) DEFAULT NULL, closed_by_id BINARY(16) DEFAULT NULL, INDEX IDX_4F5A850C61F9EE23 (educational_centre_id), INDEX IDX_4F5A850CC54F3401 (academic_year_id), INDEX IDX_4F5A850CB03A8386 (created_by_id), INDEX IDX_4F5A850CE1FA7797 (closed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850C61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850CC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850CB03A8386 FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850CE1FA7797 FOREIGN KEY (closed_by_id) REFERENCES teacher (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE improvement_action ADD management_review_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56EA211E8FF FOREIGN KEY (management_review_id) REFERENCES management_review (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2B3BD56EA211E8FF ON improvement_action (management_review_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE improvement_action DROP FOREIGN KEY FK_2B3BD56EA211E8FF');
        $this->addSql('DROP INDEX IDX_2B3BD56EA211E8FF ON improvement_action');
        $this->addSql('ALTER TABLE improvement_action DROP management_review_id');
        $this->addSql('DROP TABLE management_review');
    }
}
