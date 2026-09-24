<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plan de mejora: código, curso, proceso y objetivo de las acciones de mejora (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE improvement_action ADD code VARCHAR(20) DEFAULT NULL, ADD goal LONGTEXT DEFAULT NULL, ADD academic_year_id BINARY(16) DEFAULT NULL, ADD section_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56EC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56ED823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2B3BD56EC54F3401 ON improvement_action (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56ED823E37A ON improvement_action (section_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_improvement_action_centre_code ON improvement_action (educational_centre_id, code)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP INDEX uq_improvement_action_centre_code ON improvement_action');
        $this->addSql('ALTER TABLE improvement_action DROP FOREIGN KEY FK_2B3BD56EC54F3401');
        $this->addSql('ALTER TABLE improvement_action DROP FOREIGN KEY FK_2B3BD56ED823E37A');
        $this->addSql('DROP INDEX IDX_2B3BD56EC54F3401 ON improvement_action');
        $this->addSql('DROP INDEX IDX_2B3BD56ED823E37A ON improvement_action');
        $this->addSql('ALTER TABLE improvement_action DROP code, DROP goal, DROP academic_year_id, DROP section_id');
    }
}
