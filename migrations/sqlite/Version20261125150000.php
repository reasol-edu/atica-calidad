<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Docentes asociados a los elementos del catálogo y casilla «Los docentes asociados también lo entregan» de las actividades (SQLite).
 * Al deshacerla (down) se pierden los docentes asociados y la casilla de cada actividad.
 */
final class Version20261125150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Docentes asociados a los elementos del catálogo (tabla list_item_teacher) y activity.teachers_deliver (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE list_item_teacher (id BLOB NOT NULL, list_item_id BLOB NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C14E659ACE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C14E659A41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uq_list_item_teacher ON list_item_teacher (list_item_id, teacher_id)');
        $this->addSql('CREATE INDEX IDX_C14E659ACE208F53 ON list_item_teacher (list_item_id)');
        $this->addSql('CREATE INDEX IDX_C14E659A41807E1D ON list_item_teacher (teacher_id)');

        // ADD COLUMN, not the table rebuild Doctrine's diff proposes: the activity table is referenced by many others.
        // 0: ninguna actividad cambia hasta que se marque la casilla.
        $this->addSql('ALTER TABLE activity ADD COLUMN teachers_deliver BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('ALTER TABLE activity DROP COLUMN teachers_deliver');
        $this->addSql('DROP TABLE list_item_teacher');
    }
}
