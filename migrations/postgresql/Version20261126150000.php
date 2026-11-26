<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Una entrega por combinación: las listas por las que una actividad multiplica sus entregas (activity_dimension), si agrupa los
 * elementos de cada responsable (activity.split_by_element), la combinación de cada documento y de cada «completada»
 * (document.slot_combination, activity_completion.combination) y la clave de la entrega con su quinta parte (form_submission.slot_key
 * a 400) (PostgreSQL). Nada cambia en las actividades que no tienen dimensiones.
 */
final class Version20261126150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Una entrega por combinación: activity_dimension, split_by_element, slot_combination, combination y slot_key a 400 (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE activity_dimension (id UUID NOT NULL, position INT NOT NULL, dated BOOLEAN DEFAULT false NOT NULL, activity_id UUID NOT NULL, list_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_activity_dimension ON activity_dimension (activity_id, list_id)');
        $this->addSql('CREATE INDEX IDX_2D83E62B81C06096 ON activity_dimension (activity_id)');
        $this->addSql('CREATE INDEX IDX_2D83E62B3DAE168B ON activity_dimension (list_id)');
        $this->addSql('ALTER TABLE activity_dimension ADD CONSTRAINT FK_2D83E62B81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_dimension ADD CONSTRAINT FK_2D83E62B3DAE168B FOREIGN KEY (list_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document ADD slot_combination VARCHAR(400) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity_completion ADD combination VARCHAR(400) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD split_by_element BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE form_submission ALTER slot_key TYPE VARCHAR(400)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE form_submission ALTER slot_key TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE activity DROP split_by_element');
        $this->addSql('ALTER TABLE activity_completion DROP combination');
        $this->addSql('ALTER TABLE document DROP slot_combination');
        $this->addSql('DROP TABLE activity_dimension');
    }
}
