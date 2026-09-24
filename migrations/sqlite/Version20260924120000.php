<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mejora continua: finding, improvement_action, quality_attachment y finding_timeline_entry, y los ajustes quality.verification_days y notifications.quality_notifications_enabled (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE finding (id BLOB NOT NULL, code VARCHAR(20) DEFAULT NULL, title VARCHAR(255) NOT NULL, description CLOB NOT NULL, origin VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, kind VARCHAR(255) DEFAULT NULL, severity VARCHAR(255) DEFAULT NULL, reported_at DATETIME NOT NULL, classified_at DATETIME DEFAULT NULL, analysis_due_date DATE DEFAULT NULL, whys CLOB DEFAULT NULL, root_cause CLOB DEFAULT NULL, verification_due_date DATE DEFAULT NULL, effective BOOLEAN DEFAULT NULL, verification_notes CLOB DEFAULT NULL, verified_at DATETIME DEFAULT NULL, discard_reason CLOB DEFAULT NULL, closed_at DATETIME DEFAULT NULL, educational_centre_id BLOB NOT NULL, section_id BLOB DEFAULT NULL, reported_by_id BLOB DEFAULT NULL, classified_by_id BLOB DEFAULT NULL, analysis_responsible_id BLOB DEFAULT NULL, verified_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_A719133661F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A7191336D823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A719133671CE806 FOREIGN KEY (reported_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A7191336CF8EF351 FOREIGN KEY (classified_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A71913366D401C79 FOREIGN KEY (analysis_responsible_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A719133669F4B775 FOREIGN KEY (verified_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_A719133661F9EE23 ON finding (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_A7191336D823E37A ON finding (section_id)');
        $this->addSql('CREATE INDEX IDX_A719133671CE806 ON finding (reported_by_id)');
        $this->addSql('CREATE INDEX IDX_A7191336CF8EF351 ON finding (classified_by_id)');
        $this->addSql('CREATE INDEX IDX_A71913366D401C79 ON finding (analysis_responsible_id)');
        $this->addSql('CREATE INDEX IDX_A719133669F4B775 ON finding (verified_by_id)');
        $this->addSql('CREATE INDEX idx_finding_centre_status ON finding (educational_centre_id, status)');
        $this->addSql('CREATE UNIQUE INDEX uq_finding_centre_code ON finding (educational_centre_id, code)');

        $this->addSql('CREATE TABLE improvement_action (id BLOB NOT NULL, type VARCHAR(255) NOT NULL, description CLOB NOT NULL, due_date DATE DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, done_at DATETIME DEFAULT NULL, result CLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, finding_id BLOB DEFAULT NULL, responsible_teacher_id BLOB DEFAULT NULL, responsible_profile_id BLOB DEFAULT NULL, created_by_id BLOB DEFAULT NULL, done_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_2B3BD56E61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E4323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E7A20419A FOREIGN KEY (responsible_teacher_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56EF75A2D3F FOREIGN KEY (responsible_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56EB03A8386 FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B3BD56E35AE3EF9 FOREIGN KEY (done_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E61F9EE23 ON improvement_action (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E4323B5E7 ON improvement_action (finding_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E7A20419A ON improvement_action (responsible_teacher_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EF75A2D3F ON improvement_action (responsible_profile_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56EB03A8386 ON improvement_action (created_by_id)');
        $this->addSql('CREATE INDEX IDX_2B3BD56E35AE3EF9 ON improvement_action (done_by_id)');
        $this->addSql('CREATE INDEX idx_improvement_action_centre_status ON improvement_action (educational_centre_id, status)');

        $this->addSql('CREATE TABLE quality_attachment (id BLOB NOT NULL, filename VARCHAR(255) NOT NULL, uploaded_at DATETIME NOT NULL, finding_id BLOB DEFAULT NULL, action_id BLOB DEFAULT NULL, file_id BLOB NOT NULL, uploaded_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_D16F7A3B4323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D16F7A3B9D32F035 FOREIGN KEY (action_id) REFERENCES improvement_action (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D16F7A3B93CB796C FOREIGN KEY (file_id) REFERENCES document_file (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D16F7A3BA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D16F7A3B4323B5E7 ON quality_attachment (finding_id)');
        $this->addSql('CREATE INDEX IDX_D16F7A3B9D32F035 ON quality_attachment (action_id)');
        $this->addSql('CREATE INDEX IDX_D16F7A3B93CB796C ON quality_attachment (file_id)');
        $this->addSql('CREATE INDEX IDX_D16F7A3BA2B28FE8 ON quality_attachment (uploaded_by_id)');

        $this->addSql('CREATE TABLE finding_timeline_entry (id BLOB NOT NULL, kind VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL, text CLOB DEFAULT NULL, data CLOB DEFAULT NULL, finding_id BLOB NOT NULL, actor_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_1C44D0884323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1C44D08810DAF24A FOREIGN KEY (actor_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1C44D0884323B5E7 ON finding_timeline_entry (finding_id)');
        $this->addSql('CREATE INDEX IDX_1C44D08810DAF24A ON finding_timeline_entry (actor_id)');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            ('6dae7370-ad27-4b00-a7eb-f75a8a99b971', 'quality.verification_days', 'integer', '30', 1, 1, 0, 0, 365, NULL, 'settings.category.quality', 12, 10),
            ('73e2f665-23c3-4e3f-88e3-a7958837d165', 'notifications.quality_notifications_enabled', 'boolean', 'true', 1, 1, 1, NULL, NULL, NULL, 'settings.category.email_alerts', 10, 120)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ('quality.verification_days', 'notifications.quality_notifications_enabled'))");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key IN ('quality.verification_days', 'notifications.quality_notifications_enabled')");
        $this->addSql('DROP TABLE finding_timeline_entry');
        $this->addSql('DROP TABLE quality_attachment');
        $this->addSql('DROP TABLE improvement_action');
        $this->addSql('DROP TABLE finding');
    }
}
