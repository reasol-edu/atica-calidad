<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de aviso por correo electrónico y recordatorio de actividad pendiente, a nivel global, de centro y personal (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000005', 'notifications.email_notifications_enabled', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_alerts', 10, 30),
            ('00000000-0000-4000-8000-000000000006', 'notifications.pending_activity_reminder_enabled', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_alerts', 10, 40)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.email_notifications_enabled', 'notifications.pending_activity_reminder_enabled')");
    }
}
