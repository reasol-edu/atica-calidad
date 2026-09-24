<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Auditoría interna: audit_program, audit, audit_section, audit_auditor, audit_item y audit_checklist_template, y su enlace desde finding y quality_attachment (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE audit_item (id BINARY(16) NOT NULL, position INT NOT NULL, clause VARCHAR(20) DEFAULT NULL, question LONGTEXT NOT NULL, guidance LONGTEXT DEFAULT NULL, result VARCHAR(255) DEFAULT NULL, severity VARCHAR(255) DEFAULT NULL, evidence LONGTEXT DEFAULT NULL, audit_id BINARY(16) NOT NULL, INDEX IDX_39BBCB1ABD29F359 (audit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit (id BINARY(16) NOT NULL, code VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, objective LONGTEXT DEFAULT NULL, planned_month DATE NOT NULL, scheduled_at DATETIME DEFAULT NULL, status VARCHAR(255) NOT NULL, strengths LONGTEXT DEFAULT NULL, conclusion LONGTEXT DEFAULT NULL, report_issued_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, educational_centre_id BINARY(16) NOT NULL, program_id BINARY(16) NOT NULL, lead_auditor_id BINARY(16) DEFAULT NULL, report_issued_by_id BINARY(16) DEFAULT NULL, INDEX IDX_9218FF7961F9EE23 (educational_centre_id), INDEX IDX_9218FF793EB8070A (program_id), INDEX IDX_9218FF79D668E637 (lead_auditor_id), INDEX IDX_9218FF797621278E (report_issued_by_id), UNIQUE INDEX uq_audit_centre_code (educational_centre_id, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_section (audit_id BINARY(16) NOT NULL, document_section_id BINARY(16) NOT NULL, INDEX IDX_3C51AF9FBD29F359 (audit_id), INDEX IDX_3C51AF9F79E0482C (document_section_id), PRIMARY KEY (audit_id, document_section_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_auditor (audit_id BINARY(16) NOT NULL, teacher_id BINARY(16) NOT NULL, INDEX IDX_DF6A1FDDBD29F359 (audit_id), INDEX IDX_DF6A1FDD41807E1D (teacher_id), PRIMARY KEY (audit_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_program (id BINARY(16) NOT NULL, approved_at DATETIME DEFAULT NULL, educational_centre_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, approved_by_id BINARY(16) DEFAULT NULL, INDEX IDX_83CFA2F461F9EE23 (educational_centre_id), INDEX IDX_83CFA2F42D234F6A (approved_by_id), UNIQUE INDEX uq_audit_program_year (academic_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_checklist_template (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, clause VARCHAR(20) DEFAULT NULL, items JSON NOT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_9168EE2F61F9EE23 (educational_centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_item ADD CONSTRAINT FK_39BBCB1ABD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF7961F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF793EB8070A FOREIGN KEY (program_id) REFERENCES audit_program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF79D668E637 FOREIGN KEY (lead_auditor_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF797621278E FOREIGN KEY (report_issued_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit_section ADD CONSTRAINT FK_3C51AF9FBD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_section ADD CONSTRAINT FK_3C51AF9F79E0482C FOREIGN KEY (document_section_id) REFERENCES document_section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_auditor ADD CONSTRAINT FK_DF6A1FDDBD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_auditor ADD CONSTRAINT FK_DF6A1FDD41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_program ADD CONSTRAINT FK_83CFA2F461F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_program ADD CONSTRAINT FK_83CFA2F4C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_program ADD CONSTRAINT FK_83CFA2F42D234F6A FOREIGN KEY (approved_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit_checklist_template ADD CONSTRAINT FK_9168EE2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quality_attachment ADD audit_item_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE quality_attachment ADD CONSTRAINT FK_D16F7A3B34D872E FOREIGN KEY (audit_item_id) REFERENCES audit_item (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_D16F7A3B34D872E ON quality_attachment (audit_item_id)');
        $this->addSql('ALTER TABLE finding ADD audit_item_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A719133634D872E FOREIGN KEY (audit_item_id) REFERENCES audit_item (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A719133634D872E ON finding (audit_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE finding DROP FOREIGN KEY FK_A719133634D872E');
        $this->addSql('DROP INDEX IDX_A719133634D872E ON finding');
        $this->addSql('ALTER TABLE finding DROP audit_item_id');
        $this->addSql('DELETE FROM quality_attachment WHERE audit_item_id IS NOT NULL');
        $this->addSql('ALTER TABLE quality_attachment DROP FOREIGN KEY FK_D16F7A3B34D872E');
        $this->addSql('DROP INDEX IDX_D16F7A3B34D872E ON quality_attachment');
        $this->addSql('ALTER TABLE quality_attachment DROP audit_item_id');
        $this->addSql('ALTER TABLE audit_item DROP FOREIGN KEY FK_39BBCB1ABD29F359');
        $this->addSql('ALTER TABLE audit_section DROP FOREIGN KEY FK_3C51AF9FBD29F359');
        $this->addSql('ALTER TABLE audit_auditor DROP FOREIGN KEY FK_DF6A1FDDBD29F359');
        $this->addSql('ALTER TABLE audit DROP FOREIGN KEY FK_9218FF793EB8070A');
        $this->addSql('DROP TABLE audit_checklist_template');
        $this->addSql('DROP TABLE audit_item');
        $this->addSql('DROP TABLE audit_auditor');
        $this->addSql('DROP TABLE audit_section');
        $this->addSql('DROP TABLE audit');
        $this->addSql('DROP TABLE audit_program');
    }
}
