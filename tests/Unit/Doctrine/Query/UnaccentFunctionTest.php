<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine\Query;

use App\Doctrine\Query\UnaccentFunction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\SqlWalker;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * getSql() is the only method exercised — parse() needs a real DQL Lexer/Parser and is already
 * covered end to end by every repository/autocompleter search test in the suite (they all run
 * DQL containing UNACCENT() against the SQLite test database; a parse failure there would fail
 * every one of them, not just search-specific tests).
 */
final class UnaccentFunctionTest extends TestCase
{
    /**
     * isPostgresUnaccentAvailable() memoizes its result in a private static for the life of the
     * process (see the class's own docblock) — reset it around each test so one test's mocked
     * Connection never leaks into the next.
     */
    #[Before]
    #[After]
    protected function resetMemoizedAvailability(): void
    {
        $property = new \ReflectionProperty(UnaccentFunction::class, 'postgresUnaccentAvailable');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function walkerFor(Connection $connection): SqlWalker
    {
        $walker = $this->createStub(SqlWalker::class);
        $walker->method('getConnection')->willReturn($connection);
        $walker->method('walkSimpleArithmeticExpression')->willReturn('LOWER(t.name)');

        return $walker;
    }

    private function connectionFor(object $platform): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }

    public function testWrapsWithUnaccentOnPostgresqlWhenTheExtensionIsAvailable(): void
    {
        $connection = $this->connectionFor(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(1);

        $function = new UnaccentFunction('UNACCENT');
        $function->stringPrimary = $this->createStub(Node::class);

        self::assertSame('unaccent(LOWER(t.name))', $function->getSql($this->walkerFor($connection)));
    }

    public function testDegradesToThePlainExpressionOnPostgresqlWhenTheExtensionIsMissing(): void
    {
        $connection = $this->connectionFor(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(0);

        $function = new UnaccentFunction('UNACCENT');
        $function->stringPrimary = $this->createStub(Node::class);

        self::assertSame('LOWER(t.name)', $function->getSql($this->walkerFor($connection)));
    }

    public function testDegradesToThePlainExpressionOnPostgresqlWhenCheckingTheExtensionThrows(): void
    {
        $connection = $this->connectionFor(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('sin permisos'));

        $function = new UnaccentFunction('UNACCENT');
        $function->stringPrimary = $this->createStub(Node::class);

        self::assertSame('LOWER(t.name)', $function->getSql($this->walkerFor($connection)));
    }

    public function testAddsACollateClauseOnMysql(): void
    {
        $connection = $this->connectionFor($this->createStub(AbstractMySQLPlatform::class));

        $function = new UnaccentFunction('UNACCENT');
        $function->stringPrimary = $this->createStub(Node::class);

        self::assertSame('(LOWER(t.name) COLLATE utf8mb4_unicode_ci)', $function->getSql($this->walkerFor($connection)));
    }

    public function testLeavesTheExpressionUnchangedOnAnyOtherPlatform(): void
    {
        $connection = $this->connectionFor(new SqlitePlatform());

        $function = new UnaccentFunction('UNACCENT');
        $function->stringPrimary = $this->createStub(Node::class);

        self::assertSame('LOWER(t.name)', $function->getSql($this->walkerFor($connection)));
    }
}
