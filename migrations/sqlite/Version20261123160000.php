<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El encabezado predeterminado de los documentos pasa a llevar los logotipos del centro y de la administración educativa, como los
 * documentos ODT del centro: cinco columnas, con un logotipo a cada lado (SQLite). Solo cambia el valor predeterminado: lo que un
 * centro o la plataforma hayan escrito se queda como está.
 */
final class Version20261123160000 extends AbstractMigration
{
    private const string NEW = '<table style="width: 100%; border-collapse: collapse"><tbody><tr><td rowspan="3" data-colwidth="360" style="border: 1px solid #555555; padding: 4px; text-align: center; vertical-align: middle"><p style="text-align: center">{logo}</p></td><td colspan="3" data-colwidth="480,232,272" style="border: 1px solid #555555; padding: 4px; text-align: center; vertical-align: middle; background-color: #eef2ea"><p style="text-align: center"><strong>{centro} - {localidad}</strong></p></td><td rowspan="3" data-colwidth="360" style="border: 1px solid #555555; padding: 4px; text-align: center; vertical-align: middle"><p style="text-align: center">{logo2}</p></td></tr><tr><td colspan="3" style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center"><strong>{titulo}</strong></p></td></tr><tr><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">{codigo}</p></td><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">REV: {revision}</p></td><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">Página {pagina} de {paginas}</p></td></tr></tbody></table>';

    private const string OLD = '<table style="width: 100%; border-collapse: collapse"><tbody><tr><td colspan="3" style="border: 1px solid #555555; padding: 4px; text-align: center; background-color: #eef2ea"><p style="text-align: center"><strong>{centro} - {localidad}</strong></p></td></tr><tr><td colspan="3" style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center"><strong>{titulo}</strong></p></td></tr><tr><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">{codigo}</p></td><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">REV: {revision}</p></td><td style="border: 1px solid #555555; padding: 4px; text-align: center"><p style="text-align: center">Página {pagina} de {paginas}</p></td></tr></tbody></table>';

    public function getDescription(): string
    {
        return 'Encabezado predeterminado de los documentos con los logotipos (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("UPDATE setting_definition SET default_value = ? WHERE key = 'forms.document_header'", [self::NEW]);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("UPDATE setting_definition SET default_value = ? WHERE key = 'forms.document_header'", [self::OLD]);
    }
}
