<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Listas asociadas: un elemento del catálogo se asocia a elementos de otras listas (list_item_link), y cada lista declara con qué
 * listas se asocia (list_item_linked_list). Las asociaciones con «perfil › subperfil» pasan a ser asociaciones con ese elemento de la
 * lista del perfil; las asociaciones con un perfil sin lista se quedan en list_item_profile, que pierde la columna subprofile_id
 * (MySQL/MariaDB). Al deshacerla, un vínculo con un elemento que no está en la lista de ningún perfil se pierde.
 */
final class Version20261126100000 extends AbstractMigration
{
    /** Each item with the root of its list. */
    private const ROOTS = 'WITH RECURSIVE r (id, root_id) AS (SELECT id, id FROM list_item WHERE parent_id IS NULL UNION ALL SELECT c.id, r.root_id FROM list_item c JOIN r ON c.parent_id = r.id)';

    public function getDescription(): string
    {
        return 'Listas asociadas: list_item_link y list_item_linked_list, copia desde list_item_profile con subperfil (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('CREATE TABLE list_item_link (list_item_id BINARY(16) NOT NULL, linked_item_id BINARY(16) NOT NULL, INDEX IDX_B350D161CE208F53 (list_item_id), INDEX IDX_B350D16179276AC9 (linked_item_id), PRIMARY KEY (list_item_id, linked_item_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE list_item_linked_list (list_id BINARY(16) NOT NULL, linked_list_id BINARY(16) NOT NULL, INDEX IDX_CB3F280A3DAE168B (list_id), INDEX IDX_CB3F280A56E62E1C (linked_list_id), PRIMARY KEY (list_id, linked_list_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE list_item_link ADD CONSTRAINT FK_B350D161CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_link ADD CONSTRAINT FK_B350D16179276AC9 FOREIGN KEY (linked_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_linked_list ADD CONSTRAINT FK_CB3F280A3DAE168B FOREIGN KEY (list_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_linked_list ADD CONSTRAINT FK_CB3F280A56E62E1C FOREIGN KEY (linked_list_id) REFERENCES list_item (id) ON DELETE CASCADE');

        // «Jefe/a de Departamento › Informática» on an element becomes «Departamento › Informática», and its list is declared associated.
        $this->addSql('INSERT INTO list_item_link (list_item_id, linked_item_id) SELECT DISTINCT list_item_id, subprofile_id FROM list_item_profile WHERE subprofile_id IS NOT NULL');
        $this->addSql('INSERT INTO list_item_linked_list (list_id, linked_list_id) ' . self::ROOTS . ' SELECT DISTINCT a.root_id, b.root_id FROM list_item_profile p JOIN r a ON a.id = p.list_item_id JOIN r b ON b.id = p.subprofile_id WHERE p.subprofile_id IS NOT NULL');
        $this->addSql('DELETE FROM list_item_profile WHERE subprofile_id IS NOT NULL');
        // The old unique index had the nullable subprofile_id in it, so nothing stopped the same element and profile twice: one each.
        $this->addSql('DELETE a FROM list_item_profile a JOIN list_item_profile b ON a.list_item_id = b.list_item_id AND a.specific_profile_id = b.specific_profile_id AND a.id > b.id');

        $this->addSql('ALTER TABLE list_item_profile DROP FOREIGN KEY FK_F0EF6940936BF51B');
        $this->addSql('DROP INDEX IDX_F0EF6940936BF51B ON list_item_profile');
        $this->addSql('DROP INDEX uq_list_item_profile ON list_item_profile');
        $this->addSql('ALTER TABLE list_item_profile DROP subprofile_id');
        $this->addSql('CREATE UNIQUE INDEX uq_list_item_profile ON list_item_profile (list_item_id, specific_profile_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL/MariaDB.');

        $this->addSql('DROP INDEX uq_list_item_profile ON list_item_profile');
        $this->addSql('ALTER TABLE list_item_profile ADD subprofile_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE list_item_profile ADD CONSTRAINT FK_F0EF6940936BF51B FOREIGN KEY (subprofile_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_F0EF6940936BF51B ON list_item_profile (subprofile_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_list_item_profile ON list_item_profile (list_item_id, specific_profile_id, subprofile_id)');

        // Back to «perfil › subperfil»: the profile whose list holds the linked element (the first one, when several share it).
        $this->addSql(<<<'SQL'
            INSERT INTO list_item_profile (id, list_item_id, specific_profile_id, subprofile_id)
            WITH RECURSIVE up (item_id, ancestor_id) AS (
                SELECT id, id FROM list_item
                UNION ALL
                SELECT up.item_id, li.parent_id FROM up JOIN list_item li ON li.id = up.ancestor_id WHERE li.parent_id IS NOT NULL
            ),
            owner AS (
                SELECT up.item_id, sp.id AS profile_id, ROW_NUMBER() OVER (PARTITION BY up.item_id ORDER BY sp.name, sp.id) AS n
                FROM up JOIN specific_profile sp ON sp.list_item_id = up.ancestor_id
            )
            SELECT UNHEX(REPLACE(UUID(), '-', '')), l.list_item_id, o.profile_id, l.linked_item_id
            FROM list_item_link l JOIN owner o ON o.item_id = l.linked_item_id AND o.n = 1
            SQL);

        $this->addSql('DROP TABLE list_item_link');
        $this->addSql('DROP TABLE list_item_linked_list');
    }
}
