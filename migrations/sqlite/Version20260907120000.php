<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registro de actividad (auditoría): tabla activity_log y ajustes audit.* (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE activity_log (id BLOB NOT NULL, created_at DATETIME NOT NULL, ip VARCHAR(45) NOT NULL, action_type VARCHAR(100) NOT NULL, route VARCHAR(255) DEFAULT NULL, method VARCHAR(10) DEFAULT NULL, status_code SMALLINT DEFAULT NULL, data CLOB DEFAULT NULL, active_user_id BLOB DEFAULT NULL, real_user_id BLOB DEFAULT NULL, educational_centre_id BLOB DEFAULT NULL, academic_year_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_FD06F64724226F8B FOREIGN KEY (active_user_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FD06F647A0511301 FOREIGN KEY (real_user_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FD06F64761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FD06F647C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FD06F64724226F8B ON activity_log (active_user_id)');
        $this->addSql('CREATE INDEX IDX_FD06F647A0511301 ON activity_log (real_user_id)');
        $this->addSql('CREATE INDEX IDX_FD06F64761F9EE23 ON activity_log (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_FD06F647C54F3401 ON activity_log (academic_year_id)');
        $this->addSql('CREATE INDEX idx_al_created ON activity_log (created_at)');
        $this->addSql('CREATE INDEX idx_al_user_created ON activity_log (active_user_id, created_at)');
        $this->addSql('CREATE INDEX idx_al_type_created ON activity_log (action_type, created_at)');
        $this->addSql('CREATE INDEX idx_al_centre_created ON activity_log (educational_centre_id, created_at)');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-00000000000a', 'audit.log_enabled', 'boolean', 'true', 1, 1, 0, NULL, NULL, 'settings.category.audit', 30, 10),
            ('00000000-0000-4000-8000-00000000000b', 'audit.log_retention_days', 'integer', '90', 1, 0, 0, 0, 3650, 'settings.category.audit', 30, 20)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('audit.log_enabled', 'audit.log_retention_days')");
        $this->addSql('DROP TABLE activity_log');
    }
}
