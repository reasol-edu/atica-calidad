<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de aviso por correo electrónico y recordatorio de actividad pendiente, a nivel global, de centro y personal (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.email_notifications_enabled', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_alerts', 10, 30),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.pending_activity_reminder_enabled', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_alerts', 10, 40)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('notifications.email_notifications_enabled', 'notifications.pending_activity_reminder_enabled')");
    }
}
