<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registro de actividad (auditoría): tabla activity_log y ajustes audit.* (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('CREATE TABLE activity_log (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, ip VARCHAR(45) NOT NULL, action_type VARCHAR(100) NOT NULL, route VARCHAR(255) DEFAULT NULL, method VARCHAR(10) DEFAULT NULL, status_code SMALLINT DEFAULT NULL, data JSON DEFAULT NULL, active_user_id BINARY(16) DEFAULT NULL, real_user_id BINARY(16) DEFAULT NULL, educational_centre_id BINARY(16) DEFAULT NULL, academic_year_id BINARY(16) DEFAULT NULL, INDEX IDX_FD06F64724226F8B (active_user_id), INDEX IDX_FD06F647A0511301 (real_user_id), INDEX IDX_FD06F64761F9EE23 (educational_centre_id), INDEX IDX_FD06F647C54F3401 (academic_year_id), INDEX idx_al_created (created_at), INDEX idx_al_user_created (active_user_id, created_at), INDEX idx_al_type_created (action_type, created_at), INDEX idx_al_centre_created (educational_centre_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F64724226F8B FOREIGN KEY (active_user_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F647A0511301 FOREIGN KEY (real_user_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F64761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F647C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'audit.log_enabled', 'boolean', 'true', 1, 1, 0, NULL, NULL, 'settings.category.audit', 30, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'audit.log_retention_days', 'integer', '90', 1, 0, 0, 0, 3650, 'settings.category.audit', 30, 20)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('audit.log_enabled', 'audit.log_retention_days')");
        $this->addSql('DROP TABLE activity_log');
    }
}
