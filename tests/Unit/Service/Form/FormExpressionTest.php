<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The expression language, against the same cases the JavaScript implementation is run on (tests/js/form-expression.test.mjs). */
final class FormExpressionTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/form-expressions.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $i => $case) {
            yield $i . ': ' . $case['expr'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testItGivesTheSameAnswerAsTheSharedFixture(array $case): void
    {
        $parsed = FormExpression::parse($case['expr']);

        if (($case['error'] ?? false) === true) {
            self::assertNull($parsed['ast']);
            self::assertNotNull($parsed['error']);

            return;
        }
        self::assertNotNull($parsed['ast'], (string) $parsed['error']);

        $refs   = $case['refs'] ?? [];
        $result = FormExpression::evaluate($parsed['ast'], static fn (string $ref): mixed => $refs[$ref] ?? null);

        if (is_float($result) || is_int($result)) {
            self::assertEqualsWithDelta($case['expected'], $result, 1e-9);
        } else {
            self::assertSame($case['expected'], $result);
        }
    }

    public function testItListsTheReferencesOfAFormula(): void
    {
        $parsed = FormExpression::parse('sum([t:a.b]) / count([c:s.f:x]) + [f:y]');

        self::assertSame(['t:a.b', 'c:s.f:x', 'f:y'], FormExpression::references($parsed['ast'] ?? []));
    }
}
