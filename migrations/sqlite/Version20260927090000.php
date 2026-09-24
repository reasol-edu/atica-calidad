<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Internal audits. finding and quality_attachment get their audit_item_id with ALTER TABLE, as in
 * Version20260926090000 and for the same reason: rebuilding them with foreign keys on would
 * cascade-delete their actions, attachments and timelines. Going down rebuilds them with foreign
 * keys off, which SQLite only allows outside a transaction — hence isTransactional().
 */
final class Version20260927090000 extends AbstractMigration
{
    private const string FINDING_COLUMNS = 'id, code, title, description, origin, status, kind, severity, reported_at, classified_at, analysis_due_date, whys, root_cause, verification_due_date, effective, verification_notes, verified_at, discard_reason, closed_at, educational_centre_id, section_id, reported_by_id, classified_by_id, analysis_responsible_id, verified_by_id, measurement_id';

    private const string ATTACHMENT_COLUMNS = 'id, filename, uploaded_at, finding_id, action_id, file_id, uploaded_by_id';

    public function getDescription(): string
    {
        return 'Auditoría interna: audit_program, audit, audit_section, audit_auditor, audit_item y audit_checklist_template, y su enlace desde finding y quality_attachment (SQLite)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE audit_program (id BLOB NOT NULL, approved_at DATETIME DEFAULT NULL, educational_centre_id BLOB NOT NULL, academic_year_id BLOB NOT NULL, approved_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_83CFA2F461F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_83CFA2F4C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_83CFA2F42D234F6A FOREIGN KEY (approved_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_83CFA2F461F9EE23 ON audit_program (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_83CFA2F42D234F6A ON audit_program (approved_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_audit_program_year ON audit_program (academic_year_id)');

        $this->addSql('CREATE TABLE audit (id BLOB NOT NULL, code VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, objective CLOB DEFAULT NULL, planned_month DATE NOT NULL, scheduled_at DATETIME DEFAULT NULL, status VARCHAR(255) NOT NULL, strengths CLOB DEFAULT NULL, conclusion CLOB DEFAULT NULL, report_issued_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, educational_centre_id BLOB NOT NULL, program_id BLOB NOT NULL, lead_auditor_id BLOB DEFAULT NULL, report_issued_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_9218FF7961F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9218FF793EB8070A FOREIGN KEY (program_id) REFERENCES audit_program (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9218FF79D668E637 FOREIGN KEY (lead_auditor_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9218FF797621278E FOREIGN KEY (report_issued_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9218FF7961F9EE23 ON audit (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_9218FF793EB8070A ON audit (program_id)');
        $this->addSql('CREATE INDEX IDX_9218FF79D668E637 ON audit (lead_auditor_id)');
        $this->addSql('CREATE INDEX IDX_9218FF797621278E ON audit (report_issued_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_audit_centre_code ON audit (educational_centre_id, code)');

        $this->addSql('CREATE TABLE audit_section (audit_id BLOB NOT NULL, document_section_id BLOB NOT NULL, PRIMARY KEY (audit_id, document_section_id), CONSTRAINT FK_3C51AF9FBD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3C51AF9F79E0482C FOREIGN KEY (document_section_id) REFERENCES document_section (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3C51AF9FBD29F359 ON audit_section (audit_id)');
        $this->addSql('CREATE INDEX IDX_3C51AF9F79E0482C ON audit_section (document_section_id)');

        $this->addSql('CREATE TABLE audit_auditor (audit_id BLOB NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (audit_id, teacher_id), CONSTRAINT FK_DF6A1FDDBD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF6A1FDD41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_DF6A1FDDBD29F359 ON audit_auditor (audit_id)');
        $this->addSql('CREATE INDEX IDX_DF6A1FDD41807E1D ON audit_auditor (teacher_id)');

        $this->addSql('CREATE TABLE audit_item (id BLOB NOT NULL, position INTEGER NOT NULL, clause VARCHAR(20) DEFAULT NULL, question CLOB NOT NULL, guidance CLOB DEFAULT NULL, result VARCHAR(255) DEFAULT NULL, severity VARCHAR(255) DEFAULT NULL, evidence CLOB DEFAULT NULL, audit_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_39BBCB1ABD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_39BBCB1ABD29F359 ON audit_item (audit_id)');

        $this->addSql('CREATE TABLE audit_checklist_template (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, clause VARCHAR(20) DEFAULT NULL, items CLOB NOT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_9168EE2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9168EE2F61F9EE23 ON audit_checklist_template (educational_centre_id)');

        $this->addSql('ALTER TABLE finding ADD COLUMN audit_item_id BLOB DEFAULT NULL CONSTRAINT FK_A719133634D872E REFERENCES audit_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_A719133634D872E ON finding (audit_item_id)');
        $this->addSql('ALTER TABLE quality_attachment ADD COLUMN audit_item_id BLOB DEFAULT NULL CONSTRAINT FK_D16F7A3B34D872E REFERENCES audit_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D16F7A3B34D872E ON quality_attachment (audit_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('PRAGMA foreign_keys = OFF');

        // An audit's evidence goes with the audit.
        $this->addSql('DELETE FROM quality_attachment WHERE audit_item_id IS NOT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__quality_attachment AS SELECT ' . self::ATTACHMENT_COLUMNS . ' FROM quality_attachment');
        $this->addSql('DROP TABLE quality_attachment');
        $this->addSql('CREATE TABLE quality_attachment (id BLOB NOT NULL, filename VARCHAR(255) NOT NULL, uploaded_at DATETIME NOT NULL, finding_id BLOB DEFAULT NULL, action_id BLOB DEFAULT NULL, file_id BLOB NOT NULL, uploaded_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_D16F7A3B4323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D16F7A3B9D32F035 FOREIGN KEY (action_id) REFERENCES improvement_action (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D16F7A3B93CB796C FOREIGN KEY (file_id) REFERENCES document_file (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D16F7A3BA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO quality_attachment (' . self::ATTACHMENT_COLUMNS . ') SELECT ' . self::ATTACHMENT_COLUMNS . ' FROM __temp__quality_attachment');
        $this->addSql('DROP TABLE __temp__quality_attachment');
        $this->addSql('CREATE INDEX IDX_D16F7A3B4323B5E7 ON quality_attachment (finding_id)');
        $this->addSql('CREATE INDEX IDX_D16F7A3B9D32F035 ON quality_attachment (action_id)');
        $this->addSql('CREATE INDEX IDX_D16F7A3B93CB796C ON quality_attachment (file_id)');
        $this->addSql('CREATE INDEX IDX_D16F7A3BA2B28FE8 ON quality_attachment (uploaded_by_id)');

        $this->addSql('CREATE TEMPORARY TABLE __temp__finding AS SELECT ' . self::FINDING_COLUMNS . ' FROM finding');
        $this->addSql('DROP TABLE finding');
        $this->addSql('CREATE TABLE finding (id BLOB NOT NULL, code VARCHAR(20) DEFAULT NULL, title VARCHAR(255) NOT NULL, description CLOB NOT NULL, origin VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, kind VARCHAR(255) DEFAULT NULL, severity VARCHAR(255) DEFAULT NULL, reported_at DATETIME NOT NULL, classified_at DATETIME DEFAULT NULL, analysis_due_date DATE DEFAULT NULL, whys CLOB DEFAULT NULL, root_cause CLOB DEFAULT NULL, verification_due_date DATE DEFAULT NULL, effective BOOLEAN DEFAULT NULL, verification_notes CLOB DEFAULT NULL, verified_at DATETIME DEFAULT NULL, discard_reason CLOB DEFAULT NULL, closed_at DATETIME DEFAULT NULL, educational_centre_id BLOB NOT NULL, section_id BLOB DEFAULT NULL, reported_by_id BLOB DEFAULT NULL, classified_by_id BLOB DEFAULT NULL, analysis_responsible_id BLOB DEFAULT NULL, verified_by_id BLOB DEFAULT NULL, measurement_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_A7191336924EA134 FOREIGN KEY (measurement_id) REFERENCES measurement (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A719133661F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A7191336D823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A719133671CE806 FOREIGN KEY (reported_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A7191336CF8EF351 FOREIGN KEY (classified_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A71913366D401C79 FOREIGN KEY (analysis_responsible_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A719133669F4B775 FOREIGN KEY (verified_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO finding (' . self::FINDING_COLUMNS . ') SELECT ' . self::FINDING_COLUMNS . ' FROM __temp__finding');
        $this->addSql('DROP TABLE __temp__finding');
        $this->addSql('CREATE INDEX IDX_A7191336924EA134 ON finding (measurement_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_finding_centre_code ON finding (educational_centre_id, code)');
        $this->addSql('CREATE INDEX idx_finding_centre_status ON finding (educational_centre_id, status)');
        $this->addSql('CREATE INDEX IDX_A719133669F4B775 ON finding (verified_by_id)');
        $this->addSql('CREATE INDEX IDX_A71913366D401C79 ON finding (analysis_responsible_id)');
        $this->addSql('CREATE INDEX IDX_A7191336CF8EF351 ON finding (classified_by_id)');
        $this->addSql('CREATE INDEX IDX_A719133671CE806 ON finding (reported_by_id)');
        $this->addSql('CREATE INDEX IDX_A7191336D823E37A ON finding (section_id)');
        $this->addSql('CREATE INDEX IDX_A719133661F9EE23 ON finding (educational_centre_id)');

        $this->addSql('DROP TABLE audit_checklist_template');
        $this->addSql('DROP TABLE audit_item');
        $this->addSql('DROP TABLE audit_auditor');
        $this->addSql('DROP TABLE audit_section');
        $this->addSql('DROP TABLE audit');
        $this->addSql('DROP TABLE audit_program');

        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
