<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del recordatorio semanal de revisión de documentos: notifications.document_next_review_reminder_enabled y _warning_days, a nivel global, de centro y personal (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            ('56da0e9e-5734-4648-ba5e-0fd7c46fa52b', 'notifications.document_next_review_reminder_enabled', 'boolean', 'true', 1, 1, 1, NULL, NULL, NULL, 'settings.category.email_alerts', 10, 100),
            ('65549097-5166-42b3-bc37-2a2baec219b9', 'notifications.document_next_review_reminder_warning_days', 'integer', '30', 1, 1, 1, 0, 365, NULL, 'settings.category.email_alerts', 10, 110)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.document_next_review_reminder_enabled', 'notifications.document_next_review_reminder_warning_days')");
    }
}
