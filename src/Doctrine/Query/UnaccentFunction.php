<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

use function sprintf;

/**
 * "UNACCENT" "(" StringPrimary ")"
 *
 * Envuelve una expresión para que las búsquedas y filtros por texto (siempre usada junto a LIKE,
 * sobre un LOWER() ya existente — ver cualquier repositorio) ignoren tildes/diéresis: buscar "Jose"
 * encuentra también "José". El comportamiento depende de la plataforma, resuelto en cada caso sin
 * tocar el esquema:
 *
 * - **PostgreSQL**: envuelve con la función `unaccent()` — ver la migración que activa la extensión
 *   del mismo nombre. Si la extensión no está instalada (permisos insuficientes, o una instalación
 *   anterior a que la migración pudiera crearla), se degrada de forma transparente devolviendo la
 *   expresión sin envolver, idéntico a como quedaría sin esta función: la aplicación sigue
 *   funcionando con normalidad, solo sin esta mejora.
 * - **MySQL / MariaDB**: añade `COLLATE utf8mb4_unicode_ci` a la expresión — esa collation compara
 *   ignorando tildes, a diferencia de la que trae el servidor por defecto en muchas instalaciones
 *   (que varía y no se puede dar por hecha). No hace falta tocar la collation ya almacenada en las
 *   columnas: alcanza con fijarla en la propia comparación.
 * - **Cualquier otra plataforma (SQLite)**: se degrada devolviendo la expresión sin envolver —
 *   SQLite no tiene ninguna normalización Unicode nativa equivalente.
 */
class UnaccentFunction extends FunctionNode
{
    /** Se resuelve una sola vez por proceso: no cambia mientras la app está en marcha. */
    private static ?bool $postgresUnaccentAvailable = null;

    public Node $stringPrimary;

    public function getSql(SqlWalker $sqlWalker): string
    {
        $inner      = $sqlWalker->walkSimpleArithmeticExpression($this->stringPrimary);
        $connection = $sqlWalker->getConnection();
        $platform   = $connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            return self::isPostgresUnaccentAvailable($connection) ? sprintf('unaccent(%s)', $inner) : $inner;
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('(%s COLLATE utf8mb4_unicode_ci)', $inner);
        }

        return $inner;
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->stringPrimary = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    private static function isPostgresUnaccentAvailable(Connection $connection): bool
    {
        if (self::$postgresUnaccentAvailable === null) {
            try {
                self::$postgresUnaccentAvailable = (bool) $connection->fetchOne(
                    "SELECT 1 FROM pg_extension WHERE extname = 'unaccent'"
                );
            } catch (\Throwable) {
                self::$postgresUnaccentAvailable = false;
            }
        }

        return self::$postgresUnaccentAvailable;
    }
}
