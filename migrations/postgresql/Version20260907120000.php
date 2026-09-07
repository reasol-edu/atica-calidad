<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registro de actividad (auditoría): tabla activity_log y ajustes audit.* (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('CREATE TABLE activity_log (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ip VARCHAR(45) NOT NULL, action_type VARCHAR(100) NOT NULL, route VARCHAR(255) DEFAULT NULL, method VARCHAR(10) DEFAULT NULL, status_code SMALLINT DEFAULT NULL, data JSON DEFAULT NULL, active_user_id UUID DEFAULT NULL, real_user_id UUID DEFAULT NULL, educational_centre_id UUID DEFAULT NULL, academic_year_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FD06F64724226F8B ON activity_log (active_user_id)');
        $this->addSql('CREATE INDEX IDX_FD06F647A0511301 ON activity_log (real_user_id)');
        $this->addSql('CREATE INDEX IDX_FD06F64761F9EE23 ON activity_log (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_FD06F647C54F3401 ON activity_log (academic_year_id)');
        $this->addSql('CREATE INDEX idx_al_created ON activity_log (created_at)');
        $this->addSql('CREATE INDEX idx_al_user_created ON activity_log (active_user_id, created_at)');
        $this->addSql('CREATE INDEX idx_al_type_created ON activity_log (action_type, created_at)');
        $this->addSql('CREATE INDEX idx_al_centre_created ON activity_log (educational_centre_id, created_at)');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F64724226F8B FOREIGN KEY (active_user_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F647A0511301 FOREIGN KEY (real_user_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F64761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F647C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'audit.log_enabled', 'boolean', 'true', TRUE, TRUE, FALSE, NULL, NULL, 'settings.category.audit', 30, 10),
                (gen_random_uuid(), 'audit.log_retention_days', 'integer', '90', TRUE, FALSE, FALSE, 0, 3650, 'settings.category.audit', 30, 20)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('audit.log_enabled', 'audit.log_retention_days')");
        $this->addSql('DROP TABLE activity_log');
    }
}
