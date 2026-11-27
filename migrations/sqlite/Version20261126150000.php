<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Una entrega por combinación: las listas por las que una actividad multiplica sus entregas (activity_dimension), si agrupa los
 * elementos de cada responsable (activity.split_by_element), la combinación de cada documento y de cada «completada»
 * (document.slot_combination, activity_completion.combination) y la clave de la entrega con su quinta parte (form_submission.slot_key
 * a 400) (SQLite). Nada cambia en las actividades que no tienen dimensiones.
 *
 * form_submission se reconstruye (SQLite no cambia una columna): con las claves ajenas activas (SqlitePragmasMiddleware), borrarla
 * borraría en cascada sus revisiones, el historial de campos y los anexos. Se desactivan para la reconstrucción, y SQLite solo lo
 * permite fuera de una transacción: de ahí isTransactional().
 */
final class Version20261126150000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Una entrega por combinación: activity_dimension, split_by_element, slot_combination, combination y slot_key a 400 (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE activity_dimension (id BLOB NOT NULL, position INTEGER NOT NULL, dated BOOLEAN DEFAULT 0 NOT NULL, activity_id BLOB NOT NULL, list_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_2D83E62B81C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2D83E62B3DAE168B FOREIGN KEY (list_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uq_activity_dimension ON activity_dimension (activity_id, list_id)');
        $this->addSql('CREATE INDEX IDX_2D83E62B81C06096 ON activity_dimension (activity_id)');
        $this->addSql('CREATE INDEX IDX_2D83E62B3DAE168B ON activity_dimension (list_id)');
        $this->addSql('ALTER TABLE document ADD COLUMN slot_combination VARCHAR(400) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity_completion ADD COLUMN combination VARCHAR(400) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD COLUMN split_by_element BOOLEAN DEFAULT 1 NOT NULL');
        $this->rebuildFormSubmission(400);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->rebuildFormSubmission(255);
        $this->addSql('ALTER TABLE activity DROP COLUMN split_by_element');
        $this->addSql('ALTER TABLE activity_completion DROP COLUMN combination');
        $this->addSql('ALTER TABLE document DROP COLUMN slot_combination');
        $this->addSql('DROP TABLE activity_dimension');
    }

    /** SQLite cannot change a column: the table is made again with slot_key of $length. */
    private function rebuildFormSubmission(int $length): void
    {
        $columns = 'id, slot_key, cycle_year, draft_data, draft_version, updated_at, activity_id, model_revision_id, document_id, updated_by_id, locked_until, locked_by_id, prefilled_from';
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__form_submission AS SELECT ' . $columns . ' FROM form_submission');
        $this->addSql('DROP TABLE form_submission');
        $this->addSql('CREATE TABLE form_submission (id BLOB NOT NULL, slot_key VARCHAR(' . $length . ') NOT NULL, cycle_year INTEGER NOT NULL, draft_data CLOB DEFAULT NULL, draft_version INTEGER NOT NULL, updated_at DATETIME NOT NULL, activity_id BLOB NOT NULL, model_revision_id BLOB NOT NULL, document_id BLOB DEFAULT NULL, updated_by_id BLOB DEFAULT NULL, locked_until DATETIME DEFAULT NULL, locked_by_id BLOB DEFAULT NULL, prefilled_from CLOB DEFAULT NULL, PRIMARY KEY (id), FOREIGN KEY (locked_by_id) REFERENCES teacher (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D2C2166781C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D2C2166773F84563 FOREIGN KEY (model_revision_id) REFERENCES form_model_revision (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D2C21667C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D2C21667896DBBDE FOREIGN KEY (updated_by_id) REFERENCES teacher (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO form_submission (' . $columns . ') SELECT ' . $columns . ' FROM __temp__form_submission');
        $this->addSql('DROP TABLE __temp__form_submission');
        $this->addSql('CREATE INDEX IDX_D2C216677A88E00 ON form_submission (locked_by_id)');
        $this->addSql('CREATE INDEX IDX_D2C21667896DBBDE ON form_submission (updated_by_id)');
        $this->addSql('CREATE INDEX IDX_D2C2166773F84563 ON form_submission (model_revision_id)');
        $this->addSql('CREATE INDEX IDX_D2C2166781C06096 ON form_submission (activity_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_form_submission_slot ON form_submission (activity_id, slot_key, cycle_year)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2C21667C33F7837 ON form_submission (document_id)');
        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
