<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generador de calendarios: orientación de página y escala de tipografía por calendario (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql("ALTER TABLE printable_calendar ADD orientation VARCHAR(255) NOT NULL DEFAULT 'portrait'");
        $this->addSql('ALTER TABLE printable_calendar ADD font_size_scale INT NOT NULL DEFAULT 100');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE printable_calendar DROP orientation');
        $this->addSql('ALTER TABLE printable_calendar DROP font_size_scale');
    }
}
