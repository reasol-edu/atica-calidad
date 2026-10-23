<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Listas: un elemento puede asociarse a varios perfiles o subperfiles. SQLite no puede quitar
 * columnas con clave ajena, así que list_item se reconstruye con las claves ajenas desactivadas
 * (reconstruirla con ellas activas borraría en cascada sus hijos y sus etiquetas): sólo se admite
 * fuera de una transacción, de ahí isTransactional(). Los identificadores de las filas nuevas se
 * generan en PHP porque SQLite no sabe generar UUID válidos.
 */
final class Version20261023100000 extends AbstractMigration
{
    /** @var list<array{item: string, profile: string, sub: ?string}> */
    private array $carried = [];

    public function getDescription(): string
    {
        return 'Listas: un elemento puede asociarse a varios perfiles o subperfiles (tabla list_item_profile, que sustituye a las dos columnas de list_item) (SQLite)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        // Each element's current association becomes its first row. One that was left half-way (a
        // profile with a list but no subprofile, after its subprofile was deleted) was already
        // treated as no association at all, so it is not carried over. Read now, written in postUp().
        /** @var list<array{item: string, profile: string, sub: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT li.id AS item, li.associated_profile_id AS profile, li.associated_profile_list_item_id AS sub'
            . ' FROM list_item li JOIN specific_profile p ON p.id = li.associated_profile_id'
            . ' WHERE li.associated_profile_list_item_id IS NOT NULL OR p.list_item_id IS NULL',
        );
        $this->carried = $rows;

        $this->addSql('CREATE TABLE list_item_profile (id BLOB NOT NULL, list_item_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, subprofile_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_F0EF6940CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F0EF6940DF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F0EF6940936BF51B FOREIGN KEY (subprofile_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uq_list_item_profile ON list_item_profile (list_item_id, specific_profile_id, subprofile_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940CE208F53 ON list_item_profile (list_item_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940DF5533E ON list_item_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940936BF51B ON list_item_profile (subprofile_id)');

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__list_item AS SELECT id, name, position, active, parent_id, educational_centre_id FROM list_item');
        $this->addSql('DROP TABLE list_item');
        $this->addSql('CREATE TABLE list_item (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL, parent_id BLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_5AD5FAF7727ACA70 FOREIGN KEY (parent_id) REFERENCES list_item (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5AD5FAF761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO list_item (id, name, position, active, parent_id, educational_centre_id) SELECT id, name, position, active, parent_id, educational_centre_id FROM __temp__list_item');
        $this->addSql('DROP TABLE __temp__list_item');
        $this->addSql('CREATE INDEX IDX_5AD5FAF761F9EE23 ON list_item (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_5AD5FAF7727ACA70 ON list_item (parent_id)');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function postUp(Schema $schema): void
    {
        foreach ($this->carried as $row) {
            $this->connection->executeStatement(
                'INSERT INTO list_item_profile (id, list_item_id, specific_profile_id, subprofile_id) VALUES (?, ?, ?, ?)',
                [Uuid::v7()->toBinary(), $row['item'], $row['profile'], $row['sub']],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, $row['sub'] === null ? ParameterType::NULL : ParameterType::BINARY],
            );
        }
        $this->carried = [];
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__list_item AS SELECT id, name, position, active, parent_id, educational_centre_id FROM list_item');
        $this->addSql('DROP TABLE list_item');
        $this->addSql('CREATE TABLE list_item (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL, parent_id BLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, associated_profile_id BLOB DEFAULT NULL, associated_profile_list_item_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_5AD5FAF7727ACA70 FOREIGN KEY (parent_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5AD5FAF761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5AD5FAF7274D33F9 FOREIGN KEY (associated_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5AD5FAF740EE1903 FOREIGN KEY (associated_profile_list_item_id) REFERENCES list_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO list_item (id, name, position, active, parent_id, educational_centre_id) SELECT id, name, position, active, parent_id, educational_centre_id FROM __temp__list_item');
        $this->addSql('DROP TABLE __temp__list_item');
        $this->addSql('CREATE INDEX IDX_5AD5FAF761F9EE23 ON list_item (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_5AD5FAF7727ACA70 ON list_item (parent_id)');
        $this->addSql('CREATE INDEX IDX_5AD5FAF7274D33F9 ON list_item (associated_profile_id)');
        $this->addSql('CREATE INDEX IDX_5AD5FAF740EE1903 ON list_item (associated_profile_list_item_id)');

        // The old model held a single association per element: the first of its rows is kept.
        $this->addSql(<<<'SQL'
            UPDATE list_item SET
                associated_profile_id = (SELECT lp.specific_profile_id FROM list_item_profile lp WHERE lp.list_item_id = list_item.id ORDER BY lp.id LIMIT 1),
                associated_profile_list_item_id = (SELECT lp.subprofile_id FROM list_item_profile lp WHERE lp.list_item_id = list_item.id ORDER BY lp.id LIMIT 1)
            SQL);

        $this->addSql('DROP TABLE list_item_profile');
        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
