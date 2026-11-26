<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Empezar cada una con lo entregado en la anterior»: con «Una entrega por» y un modelo, el borrador de la 2ª se rellena con lo
 * entregado en la 1ª (activity.form_prefill_previous, marcada por defecto) (SQLite).
 */
final class Version20261126190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Una entrega por: activity.form_prefill_previous (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE activity ADD COLUMN form_prefill_previous BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE activity DROP COLUMN form_prefill_previous');
    }
}
