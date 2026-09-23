<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajuste security.idle_timeout_minutes (cierre de sesión por inactividad), solo a nivel global (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            ('514fe59b-7885-40b3-98ff-89d06703e17a', 'security.idle_timeout_minutes', 'integer', '120', 1, 0, 0, 0, 1440, NULL, 'settings.category.security', 25, 10)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key = 'security.idle_timeout_minutes'");
    }
}
