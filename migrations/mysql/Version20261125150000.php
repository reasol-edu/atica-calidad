<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Docentes asociados a los elementos del catálogo y casilla «Los docentes asociados también lo entregan» de las actividades (MySQL/MariaDB).
 * Al deshacerla (down) se pierden los docentes asociados y la casilla de cada actividad.
 */
final class Version20261125150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Docentes asociados a los elementos del catálogo (tabla list_item_teacher) y activity.teachers_deliver (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('CREATE TABLE list_item_teacher (id BINARY(16) NOT NULL, list_item_id BINARY(16) NOT NULL, teacher_id BINARY(16) NOT NULL, UNIQUE INDEX uq_list_item_teacher (list_item_id, teacher_id), INDEX IDX_C14E659ACE208F53 (list_item_id), INDEX IDX_C14E659A41807E1D (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE list_item_teacher ADD CONSTRAINT FK_C14E659ACE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_teacher ADD CONSTRAINT FK_C14E659A41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');

        // 0: ninguna actividad cambia hasta que se marque la casilla.
        $this->addSql('ALTER TABLE activity ADD teachers_deliver TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('ALTER TABLE activity DROP teachers_deliver');
        $this->addSql('DROP TABLE list_item_teacher');
    }
}
