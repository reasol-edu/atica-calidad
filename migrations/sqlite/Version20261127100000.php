<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los nombres de los elementos del catálogo ya no contienen « › » ni « · », los separadores con que la aplicación compone rutas y
 * nombres de entregas: los que los tengan pasan a « - » (ListItem::setName() lo hace desde ahora con los nuevos) (SQLite).
 * Hacia abajo no hay vuelta: no se sabe qué « - » eran otra cosa.
 */
final class Version20261127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catálogo: « › » y « · » en los nombres de los elementos pasan a « - » (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("UPDATE list_item SET name = REPLACE(REPLACE(name, ' › ', ' - '), ' · ', ' - ') WHERE name LIKE '% › %' OR name LIKE '% · %'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');
    }
}
