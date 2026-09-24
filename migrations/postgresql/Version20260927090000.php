<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Auditoría interna: audit_program, audit, audit_section, audit_auditor, audit_item y audit_checklist_template, y su enlace desde finding y quality_attachment (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE audit_item (id UUID NOT NULL, position INT NOT NULL, clause VARCHAR(20) DEFAULT NULL, question TEXT NOT NULL, guidance TEXT DEFAULT NULL, result VARCHAR(255) DEFAULT NULL, severity VARCHAR(255) DEFAULT NULL, evidence TEXT DEFAULT NULL, audit_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_39BBCB1ABD29F359 ON audit_item (audit_id)');
        $this->addSql('CREATE TABLE audit (id UUID NOT NULL, code VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, objective TEXT DEFAULT NULL, planned_month DATE NOT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(255) NOT NULL, strengths TEXT DEFAULT NULL, conclusion TEXT DEFAULT NULL, report_issued_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, educational_centre_id UUID NOT NULL, program_id UUID NOT NULL, lead_auditor_id UUID DEFAULT NULL, report_issued_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9218FF7961F9EE23 ON audit (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_9218FF793EB8070A ON audit (program_id)');
        $this->addSql('CREATE INDEX IDX_9218FF79D668E637 ON audit (lead_auditor_id)');
        $this->addSql('CREATE INDEX IDX_9218FF797621278E ON audit (report_issued_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_audit_centre_code ON audit (educational_centre_id, code)');
        $this->addSql('CREATE TABLE audit_section (audit_id UUID NOT NULL, document_section_id UUID NOT NULL, PRIMARY KEY (audit_id, document_section_id))');
        $this->addSql('CREATE INDEX IDX_3C51AF9FBD29F359 ON audit_section (audit_id)');
        $this->addSql('CREATE INDEX IDX_3C51AF9F79E0482C ON audit_section (document_section_id)');
        $this->addSql('CREATE TABLE audit_auditor (audit_id UUID NOT NULL, teacher_id UUID NOT NULL, PRIMARY KEY (audit_id, teacher_id))');
        $this->addSql('CREATE INDEX IDX_DF6A1FDDBD29F359 ON audit_auditor (audit_id)');
        $this->addSql('CREATE INDEX IDX_DF6A1FDD41807E1D ON audit_auditor (teacher_id)');
        $this->addSql('CREATE TABLE audit_program (id UUID NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, educational_centre_id UUID NOT NULL, academic_year_id UUID NOT NULL, approved_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_83CFA2F461F9EE23 ON audit_program (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_83CFA2F42D234F6A ON audit_program (approved_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_audit_program_year ON audit_program (academic_year_id)');
        $this->addSql('CREATE TABLE audit_checklist_template (id UUID NOT NULL, name VARCHAR(255) NOT NULL, clause VARCHAR(20) DEFAULT NULL, items JSON NOT NULL, educational_centre_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9168EE2F61F9EE23 ON audit_checklist_template (educational_centre_id)');
        $this->addSql('ALTER TABLE audit_item ADD CONSTRAINT FK_39BBCB1ABD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF7961F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF793EB8070A FOREIGN KEY (program_id) REFERENCES audit_program (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF79D668E637 FOREIGN KEY (lead_auditor_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF797621278E FOREIGN KEY (report_issued_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_section ADD CONSTRAINT FK_3C51AF9FBD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_section ADD CONSTRAINT FK_3C51AF9F79E0482C FOREIGN KEY (document_section_id) REFERENCES document_section (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_auditor ADD CONSTRAINT FK_DF6A1FDDBD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_auditor ADD CONSTRAINT FK_DF6A1FDD41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_program ADD CONSTRAINT FK_83CFA2F461F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_program ADD CONSTRAINT FK_83CFA2F4C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_program ADD CONSTRAINT FK_83CFA2F42D234F6A FOREIGN KEY (approved_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_checklist_template ADD CONSTRAINT FK_9168EE2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quality_attachment ADD audit_item_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE quality_attachment ADD CONSTRAINT FK_D16F7A3B34D872E FOREIGN KEY (audit_item_id) REFERENCES audit_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D16F7A3B34D872E ON quality_attachment (audit_item_id)');
        $this->addSql('ALTER TABLE finding ADD audit_item_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A719133634D872E FOREIGN KEY (audit_item_id) REFERENCES audit_item (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A719133634D872E ON finding (audit_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE finding DROP CONSTRAINT FK_A719133634D872E');
        $this->addSql('DROP INDEX IDX_A719133634D872E');
        $this->addSql('ALTER TABLE finding DROP audit_item_id');
        $this->addSql('DELETE FROM quality_attachment WHERE audit_item_id IS NOT NULL');
        $this->addSql('ALTER TABLE quality_attachment DROP CONSTRAINT FK_D16F7A3B34D872E');
        $this->addSql('DROP INDEX IDX_D16F7A3B34D872E');
        $this->addSql('ALTER TABLE quality_attachment DROP audit_item_id');
        $this->addSql('DROP TABLE audit_checklist_template');
        $this->addSql('DROP TABLE audit_item');
        $this->addSql('DROP TABLE audit_auditor');
        $this->addSql('DROP TABLE audit_section');
        $this->addSql('DROP TABLE audit');
        $this->addSql('DROP TABLE audit_program');
    }
}
