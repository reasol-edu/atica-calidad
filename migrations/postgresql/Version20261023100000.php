<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Listas: un elemento puede asociarse a varios perfiles o subperfiles (tabla list_item_profile, que sustituye a las dos columnas de list_item) (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('CREATE TABLE list_item_profile (id UUID NOT NULL, list_item_id UUID NOT NULL, specific_profile_id UUID NOT NULL, subprofile_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_list_item_profile ON list_item_profile (list_item_id, specific_profile_id, subprofile_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940CE208F53 ON list_item_profile (list_item_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940DF5533E ON list_item_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_F0EF6940936BF51B ON list_item_profile (subprofile_id)');
        $this->addSql('ALTER TABLE list_item_profile ADD CONSTRAINT FK_F0EF6940CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE list_item_profile ADD CONSTRAINT FK_F0EF6940DF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE list_item_profile ADD CONSTRAINT FK_F0EF6940936BF51B FOREIGN KEY (subprofile_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Each element's current association becomes its first row. One that was left half-way (a
        // profile with a list but no subprofile, after its subprofile was deleted) was already
        // treated as no association at all, so it is not carried over.
        $this->addSql(<<<'SQL'
            INSERT INTO list_item_profile (id, list_item_id, specific_profile_id, subprofile_id)
            SELECT gen_random_uuid(), li.id, li.associated_profile_id, li.associated_profile_list_item_id
            FROM list_item li
            JOIN specific_profile p ON p.id = li.associated_profile_id
            WHERE li.associated_profile_list_item_id IS NOT NULL OR p.list_item_id IS NULL
            SQL);

        $this->addSql('ALTER TABLE list_item DROP CONSTRAINT fk_5ad5faf7274d33f9');
        $this->addSql('ALTER TABLE list_item DROP CONSTRAINT fk_5ad5faf740ee1903');
        $this->addSql('DROP INDEX idx_5ad5faf7274d33f9');
        $this->addSql('DROP INDEX idx_5ad5faf740ee1903');
        $this->addSql('ALTER TABLE list_item DROP associated_profile_id');
        $this->addSql('ALTER TABLE list_item DROP associated_profile_list_item_id');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Esta migración sólo puede ejecutarse en PostgreSQL.');

        $this->addSql('ALTER TABLE list_item ADD associated_profile_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE list_item ADD associated_profile_list_item_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE list_item ADD CONSTRAINT fk_5ad5faf7274d33f9 FOREIGN KEY (associated_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE list_item ADD CONSTRAINT fk_5ad5faf740ee1903 FOREIGN KEY (associated_profile_list_item_id) REFERENCES list_item (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_5ad5faf7274d33f9 ON list_item (associated_profile_id)');
        $this->addSql('CREATE INDEX idx_5ad5faf740ee1903 ON list_item (associated_profile_list_item_id)');

        // The old model held a single association per element: the first of its rows is kept.
        $this->addSql(<<<'SQL'
            UPDATE list_item SET
                associated_profile_id = (SELECT lp.specific_profile_id FROM list_item_profile lp WHERE lp.list_item_id = list_item.id ORDER BY lp.id LIMIT 1),
                associated_profile_list_item_id = (SELECT lp.subprofile_id FROM list_item_profile lp WHERE lp.list_item_id = list_item.id ORDER BY lp.id LIMIT 1)
            SQL);

        $this->addSql('DROP TABLE list_item_profile');
    }
}
