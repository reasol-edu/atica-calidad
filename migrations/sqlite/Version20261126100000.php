<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Listas asociadas: un elemento del catálogo se asocia a elementos de otras listas (list_item_link), y cada lista declara con qué
 * listas se asocia (list_item_linked_list). Las asociaciones con «perfil › subperfil» pasan a ser asociaciones con ese elemento de la
 * lista del perfil; las asociaciones con un perfil sin lista se quedan en list_item_profile, que pierde la columna subprofile_id
 * (SQLite). Al deshacerla, un vínculo con un elemento que no está en la lista de ningún perfil se pierde.
 */
final class Version20261126100000 extends AbstractMigration
{
    /** Each item with the root of its list. */
    private const ROOTS = 'WITH RECURSIVE r (id, root_id) AS (SELECT id, id FROM list_item WHERE parent_id IS NULL UNION ALL SELECT c.id, r.root_id FROM list_item c JOIN r ON c.parent_id = r.id)';

    public function getDescription(): string
    {
        return 'Listas asociadas: list_item_link y list_item_linked_list, copia desde list_item_profile con subperfil (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TABLE list_item_link (list_item_id BLOB NOT NULL, linked_item_id BLOB NOT NULL, PRIMARY KEY (list_item_id, linked_item_id), CONSTRAINT FK_B350D161CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B350D16179276AC9 FOREIGN KEY (linked_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B350D161CE208F53 ON list_item_link (list_item_id)');
        $this->addSql('CREATE INDEX IDX_B350D16179276AC9 ON list_item_link (linked_item_id)');
        $this->addSql('CREATE TABLE list_item_linked_list (list_id BLOB NOT NULL, linked_list_id BLOB NOT NULL, PRIMARY KEY (list_id, linked_list_id), CONSTRAINT FK_CB3F280A3DAE168B FOREIGN KEY (list_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CB3F280A56E62E1C FOREIGN KEY (linked_list_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_CB3F280A3DAE168B ON list_item_linked_list (list_id)');
        $this->addSql('CREATE INDEX IDX_CB3F280A56E62E1C ON list_item_linked_list (linked_list_id)');

        // «Jefe/a de Departamento › Informática» on an element becomes «Departamento › Informática», and its list is declared associated.
        $this->addSql('INSERT INTO list_item_link (list_item_id, linked_item_id) SELECT DISTINCT list_item_id, subprofile_id FROM list_item_profile WHERE subprofile_id IS NOT NULL');
        $this->addSql(self::ROOTS . ' INSERT INTO list_item_linked_list (list_id, linked_list_id) SELECT DISTINCT a.root_id, b.root_id FROM list_item_profile p JOIN r a ON a.id = p.list_item_id JOIN r b ON b.id = p.subprofile_id WHERE p.subprofile_id IS NOT NULL');

        // The plain profiles stay, in a table without subprofile_id.
        $this->addSql('CREATE TEMPORARY TABLE __temp__list_item_profile AS SELECT id, list_item_id, specific_profile_id FROM list_item_profile WHERE subprofile_id IS NULL');
        $this->addSql('DROP TABLE list_item_profile');
        $this->addSql('CREATE TABLE list_item_profile (id BLOB NOT NULL, list_item_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_F0EF6940CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F0EF6940DF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        // The old unique index had the nullable subprofile_id in it, so nothing stopped the same element and profile twice: one each.
        $this->addSql('INSERT INTO list_item_profile (id, list_item_id, specific_profile_id) SELECT MIN(id), list_item_id, specific_profile_id FROM __temp__list_item_profile GROUP BY list_item_id, specific_profile_id');
        $this->addSql('DROP TABLE __temp__list_item_profile');
        $this->addSql('CREATE INDEX IDX_F0EF6940DF5533E ON list_item_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940CE208F53 ON list_item_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_list_item_profile ON list_item_profile (list_item_id, specific_profile_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('CREATE TEMPORARY TABLE __temp__list_item_profile AS SELECT id, list_item_id, specific_profile_id FROM list_item_profile');
        $this->addSql('DROP TABLE list_item_profile');
        $this->addSql('CREATE TABLE list_item_profile (id BLOB NOT NULL, list_item_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, subprofile_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_F0EF6940CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F0EF6940DF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F0EF6940936BF51B FOREIGN KEY (subprofile_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO list_item_profile (id, list_item_id, specific_profile_id) SELECT id, list_item_id, specific_profile_id FROM __temp__list_item_profile');
        $this->addSql('DROP TABLE __temp__list_item_profile');
        $this->addSql('CREATE UNIQUE INDEX uq_list_item_profile ON list_item_profile (list_item_id, specific_profile_id, subprofile_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940CE208F53 ON list_item_profile (list_item_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940DF5533E ON list_item_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940936BF51B ON list_item_profile (subprofile_id)');

        // Back to «perfil › subperfil»: the profile whose list holds the linked element (the first one, when several share it).
        $this->addSql(<<<'SQL'
            WITH RECURSIVE up (item_id, ancestor_id) AS (
                SELECT id, id FROM list_item
                UNION ALL
                SELECT up.item_id, li.parent_id FROM up JOIN list_item li ON li.id = up.ancestor_id WHERE li.parent_id IS NOT NULL
            )
            INSERT INTO list_item_profile (id, list_item_id, specific_profile_id, subprofile_id)
            SELECT randomblob(16), l.list_item_id, (
                SELECT sp.id FROM specific_profile sp JOIN up ON up.ancestor_id = sp.list_item_id
                WHERE up.item_id = l.linked_item_id ORDER BY sp.name, sp.id LIMIT 1
            ), l.linked_item_id
            FROM list_item_link l
            WHERE EXISTS (SELECT 1 FROM specific_profile sp JOIN up ON up.ancestor_id = sp.list_item_id WHERE up.item_id = l.linked_item_id)
            SQL);

        $this->addSql('DROP TABLE list_item_link');
        $this->addSql('DROP TABLE list_item_linked_list');
    }
}
