<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Una entrega por combinación: las listas por las que una actividad multiplica sus entregas (activity_dimension), si agrupa los
 * elementos de cada responsable (activity.split_by_element), la combinación de cada documento y de cada «completada»
 * (document.slot_combination, activity_completion.combination) y la clave de la entrega con su quinta parte (form_submission.slot_key
 * a 400) (MySQL/MariaDB). Nada cambia en las actividades que no tienen dimensiones.
 */
final class Version20261126150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Una entrega por combinación: activity_dimension, split_by_element, slot_combination, combination y slot_key a 400 (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('CREATE TABLE activity_dimension (id BINARY(16) NOT NULL, position INT NOT NULL, dated TINYINT DEFAULT 0 NOT NULL, activity_id BINARY(16) NOT NULL, list_id BINARY(16) NOT NULL, UNIQUE INDEX uq_activity_dimension (activity_id, list_id), INDEX IDX_2D83E62B81C06096 (activity_id), INDEX IDX_2D83E62B3DAE168B (list_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_dimension ADD CONSTRAINT FK_2D83E62B81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE activity_dimension ADD CONSTRAINT FK_2D83E62B3DAE168B FOREIGN KEY (list_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document ADD slot_combination VARCHAR(400) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity_completion ADD combination VARCHAR(400) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD split_by_element TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE form_submission CHANGE slot_key slot_key VARCHAR(400) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('ALTER TABLE form_submission CHANGE slot_key slot_key VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE activity DROP split_by_element');
        $this->addSql('ALTER TABLE activity_completion DROP combination');
        $this->addSql('ALTER TABLE document DROP slot_combination');
        $this->addSql('DROP TABLE activity_dimension');
    }
}
