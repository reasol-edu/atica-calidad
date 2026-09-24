<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mejora continua: finding, improvement_action, quality_attachment y finding_timeline_entry, y los ajustes quality.verification_days y notifications.quality_notifications_enabled (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('CREATE TABLE finding (id BINARY(16) NOT NULL, code VARCHAR(20) DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, origin VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, kind VARCHAR(255) DEFAULT NULL, severity VARCHAR(255) DEFAULT NULL, reported_at DATETIME NOT NULL, classified_at DATETIME DEFAULT NULL, analysis_due_date DATE DEFAULT NULL, whys JSON DEFAULT NULL, root_cause LONGTEXT DEFAULT NULL, verification_due_date DATE DEFAULT NULL, effective TINYINT DEFAULT NULL, verification_notes LONGTEXT DEFAULT NULL, verified_at DATETIME DEFAULT NULL, discard_reason LONGTEXT DEFAULT NULL, closed_at DATETIME DEFAULT NULL, educational_centre_id BINARY(16) NOT NULL, section_id BINARY(16) DEFAULT NULL, reported_by_id BINARY(16) DEFAULT NULL, classified_by_id BINARY(16) DEFAULT NULL, analysis_responsible_id BINARY(16) DEFAULT NULL, verified_by_id BINARY(16) DEFAULT NULL, INDEX IDX_A719133661F9EE23 (educational_centre_id), INDEX IDX_A7191336D823E37A (section_id), INDEX IDX_A719133671CE806 (reported_by_id), INDEX IDX_A7191336CF8EF351 (classified_by_id), INDEX IDX_A71913366D401C79 (analysis_responsible_id), INDEX IDX_A719133669F4B775 (verified_by_id), INDEX idx_finding_centre_status (educational_centre_id, status), UNIQUE INDEX uq_finding_centre_code (educational_centre_id, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE improvement_action (id BINARY(16) NOT NULL, type VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, due_date DATE DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, done_at DATETIME DEFAULT NULL, result LONGTEXT DEFAULT NULL, educational_centre_id BINARY(16) NOT NULL, finding_id BINARY(16) DEFAULT NULL, responsible_teacher_id BINARY(16) DEFAULT NULL, responsible_profile_id BINARY(16) DEFAULT NULL, created_by_id BINARY(16) DEFAULT NULL, done_by_id BINARY(16) DEFAULT NULL, INDEX IDX_2B3BD56E61F9EE23 (educational_centre_id), INDEX IDX_2B3BD56E4323B5E7 (finding_id), INDEX IDX_2B3BD56E7A20419A (responsible_teacher_id), INDEX IDX_2B3BD56EF75A2D3F (responsible_profile_id), INDEX IDX_2B3BD56EB03A8386 (created_by_id), INDEX IDX_2B3BD56E35AE3EF9 (done_by_id), INDEX idx_improvement_action_centre_status (educational_centre_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quality_attachment (id BINARY(16) NOT NULL, filename VARCHAR(255) NOT NULL, uploaded_at DATETIME NOT NULL, finding_id BINARY(16) DEFAULT NULL, action_id BINARY(16) DEFAULT NULL, file_id BINARY(16) NOT NULL, uploaded_by_id BINARY(16) DEFAULT NULL, INDEX IDX_D16F7A3B4323B5E7 (finding_id), INDEX IDX_D16F7A3B9D32F035 (action_id), INDEX IDX_D16F7A3B93CB796C (file_id), INDEX IDX_D16F7A3BA2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE finding_timeline_entry (id BINARY(16) NOT NULL, kind VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL, text LONGTEXT DEFAULT NULL, data JSON DEFAULT NULL, finding_id BINARY(16) NOT NULL, actor_id BINARY(16) DEFAULT NULL, INDEX IDX_1C44D0884323B5E7 (finding_id), INDEX IDX_1C44D08810DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A719133661F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A7191336D823E37A FOREIGN KEY (section_id) REFERENCES document_section (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A719133671CE806 FOREIGN KEY (reported_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A7191336CF8EF351 FOREIGN KEY (classified_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A71913366D401C79 FOREIGN KEY (analysis_responsible_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A719133669F4B775 FOREIGN KEY (verified_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56E61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56E4323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56E7A20419A FOREIGN KEY (responsible_teacher_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56EF75A2D3F FOREIGN KEY (responsible_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56EB03A8386 FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE improvement_action ADD CONSTRAINT FK_2B3BD56E35AE3EF9 FOREIGN KEY (done_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quality_attachment ADD CONSTRAINT FK_D16F7A3B4323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quality_attachment ADD CONSTRAINT FK_D16F7A3B9D32F035 FOREIGN KEY (action_id) REFERENCES improvement_action (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quality_attachment ADD CONSTRAINT FK_D16F7A3B93CB796C FOREIGN KEY (file_id) REFERENCES document_file (id)');
        $this->addSql('ALTER TABLE quality_attachment ADD CONSTRAINT FK_D16F7A3BA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE finding_timeline_entry ADD CONSTRAINT FK_1C44D0884323B5E7 FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE finding_timeline_entry ADD CONSTRAINT FK_1C44D08810DAF24A FOREIGN KEY (actor_id) REFERENCES teacher (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'quality.verification_days', 'integer', '30', 1, 1, 0, 0, 365, NULL, 'settings.category.quality', 12, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.quality_notifications_enabled', 'boolean', 'true', 1, 1, 1, NULL, NULL, NULL, 'settings.category.email_alerts', 10, 120)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM {$table} WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` IN ('quality.verification_days', 'notifications.quality_notifications_enabled'))");
        }
        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('quality.verification_days', 'notifications.quality_notifications_enabled')");
        $this->addSql('DROP TABLE finding_timeline_entry');
        $this->addSql('DROP TABLE quality_attachment');
        $this->addSql('DROP TABLE improvement_action');
        $this->addSql('DROP TABLE finding');
    }
}
