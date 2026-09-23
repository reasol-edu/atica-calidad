<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del recordatorio semanal de revisión de documentos: notifications.document_next_review_reminder_enabled y _warning_days, a nivel global, de centro y personal (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'notifications.document_next_review_reminder_enabled', 'boolean', 'true', TRUE, TRUE, TRUE, NULL, NULL, NULL, 'settings.category.email_alerts', 10, 100),
                (gen_random_uuid(), 'notifications.document_next_review_reminder_warning_days', 'integer', '30', TRUE, TRUE, TRUE, 0, 365, NULL, 'settings.category.email_alerts', 10, 110)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.document_next_review_reminder_enabled', 'notifications.document_next_review_reminder_warning_days')");
    }
}
