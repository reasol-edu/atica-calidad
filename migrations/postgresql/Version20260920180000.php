<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Intenta activar la extensión unaccent para que las búsquedas por texto ignoren tildes (PostgreSQL)';
    }

    /**
     * No transaccional a propósito: si CREATE EXTENSION falla (p. ej. el rol de la aplicación no
     * tiene privilegio para crearla, algo habitual en hosting gestionado), el fallo se captura más
     * abajo y no debe arrastrar el resto de la migración a un rollback — la aplicación sigue
     * funcionando con normalidad sin esta extensión, solo que sin búsquedas insensibles a tildes
     * (ver App\Doctrine\Query\UnaccentFunction).
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        try {
            $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS unaccent');
            $this->write('Extensión unaccent activada: las búsquedas por texto ya ignoran tildes.');
        } catch (\Throwable $e) {
            $this->write(
                'No se ha podido activar la extensión unaccent (' . $e->getMessage() . '). '
                . 'La aplicación funciona con normalidad, pero las búsquedas seguirán siendo sensibles a tildes. '
                . 'Para activarla más tarde, pide a quien administre el servidor PostgreSQL que ejecute, conectado como superusuario: '
                . 'CREATE EXTENSION IF NOT EXISTS unaccent;'
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        try {
            $this->connection->executeStatement('DROP EXTENSION IF EXISTS unaccent');
        } catch (\Throwable $e) {
            $this->write('No se ha podido desactivar la extensión unaccent (' . $e->getMessage() . ').');
        }
    }
}
