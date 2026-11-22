<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * The small, closed expression language of the models (calculated fields and columns, consistency rules). No `eval`: a hand-written
 * parser builds a tree and a recursive evaluator walks it. The same grammar and the same results are implemented in JavaScript
 * (assets/utils/form_expression.js) and checked against one shared set of cases (tests/Fixtures/form-expressions.json), so what the
 * page shows while typing is what the server saves.
 *
 *   expr    := or;  or := and ('||' and)*;  and := cmp ('&&' cmp)*
 *   cmp     := add (('=' | '!=' | '<' | '<=' | '>' | '>=') add)?
 *   add     := mul (('+' | '-') mul)*;  mul := unary (('*' | '/') unary)*
 *   unary   := ('-' | '!') unary | primary
 *   primary := number | "text" | true | false | [ref] | name '(' [expr (';' expr)*] ')' | '(' expr ')'
 *
 * A reference is written in brackets and names things by key: `[f:KEY]` a field, `[t:TABLE.COL]` a column of a table (a list),
 * `[r:COL]` a cell of the row being calculated, `[o:COL]` a column of the row a copy comes from, `[c:SECTION.f:KEY]` or
 * `[c:SECTION.t:TABLE.COL]` the same across every copy of a repeating section, `[m:MATRIX]`, `[m:MATRIX.rows]`, `[m:MATRIX.cols]`
 * the cells of a double-entry table, or its row or column sums. Functions (arguments separated by `;`):
 * sum avg min max count (over a list, empty items ignored) · count_if(list; "op"; value) pct_if(list; "op"; value) ·
 * if(cond; a; b) · round(x; decimals) · abs(x) · empty(x).
 *
 * Empty (null) is contagious in arithmetic (a total with a missing term has no value) and false in a comparison; dividing by zero
 * has no value either. The result of an expression is a number, a text, a boolean, a list or null.
 */
final class FormExpression
{
    public const array FUNCTIONS = ['sum', 'avg', 'min', 'max', 'count', 'count_if', 'pct_if', 'if', 'round', 'abs', 'empty'];

    /**
     * @return array{ast: array<string, mixed>|null, error: ?string} the tree, or why it cannot be read
     */
    public static function parse(string $source): array
    {
        try {
            $tokens = self::tokens($source);
            $pos    = 0;
            $ast    = self::parseOr($tokens, $pos);
            if ($pos < count($tokens)) {
                throw new \InvalidArgumentException('unexpected');
            }

            return ['ast' => $ast, 'error' => null];
        } catch (\InvalidArgumentException $e) {
            return ['ast' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Every reference of an expression ({kind, path}) — to find what a formula depends on and to check it exists.
     *
     * @param array<string, mixed> $ast
     *
     * @return list<string> the text inside the brackets
     */
    public static function references(array $ast): array
    {
        $out = [];
        self::collect($ast, $out);

        return $out;
    }

    /**
     * @param array<string, mixed>        $ast
     * @param callable(string): mixed     $resolve what a reference (the text inside the brackets) is worth: a scalar, a list or null
     */
    public static function evaluate(array $ast, callable $resolve): mixed
    {
        return self::ev($ast, $resolve);
    }

    // ── Parser ───────────────────────────────────────────────────────────────

    /**
     * @return list<array{t: string, v: string|float}>
     */
    private static function tokens(string $s): array
    {
        $tokens = [];
        $i      = 0;
        $n      = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                ++$i;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($s[$i + 1]))) {
                $j = $i;
                while ($j < $n && (ctype_digit($s[$j]) || $s[$j] === '.')) {
                    ++$j;
                }
                $tokens[] = ['t' => 'num', 'v' => (float) substr($s, $i, $j - $i)];
                $i        = $j;
                continue;
            }
            if ($c === '"') {
                $j = strpos($s, '"', $i + 1);
                if ($j === false) {
                    throw new \InvalidArgumentException('string');
                }
                $tokens[] = ['t' => 'str', 'v' => substr($s, $i + 1, $j - $i - 1)];
                $i        = $j + 1;
                continue;
            }
            if ($c === '[') {
                $j = strpos($s, ']', $i + 1);
                if ($j === false) {
                    throw new \InvalidArgumentException('reference');
                }
                $tokens[] = ['t' => 'ref', 'v' => trim(substr($s, $i + 1, $j - $i - 1))];
                $i        = $j + 1;
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $n && (ctype_alnum($s[$j]) || $s[$j] === '_')) {
                    ++$j;
                }
                $tokens[] = ['t' => 'name', 'v' => strtolower(substr($s, $i, $j - $i))];
                $i        = $j;
                continue;
            }
            $two = substr($s, $i, 2);
            if (in_array($two, ['&&', '||', '!=', '<=', '>='], true)) {
                $tokens[] = ['t' => 'op', 'v' => $two];
                $i += 2;
                continue;
            }
            if (str_contains('+-*/()=<>!;', $c)) {
                $tokens[] = ['t' => 'op', 'v' => $c];
                ++$i;
                continue;
            }
            throw new \InvalidArgumentException('character');
        }

        return $tokens;
    }

    /**
     * @param list<array{t: string, v: string|float}> $tokens
     *
     * @return array<string, mixed>
     */
    private static function parseOr(array $tokens, int &$pos): array
    {
        $left = self::parseAnd($tokens, $pos);
        while (self::isOp($tokens, $pos, '||')) {
            ++$pos;
            $left = ['k' => 'or', 'a' => $left, 'b' => self::parseAnd($tokens, $pos)];
        }

        return $left;
    }

    /**
     * @param list<array{t: string, v: string|float}> $tokens
     *
     * @return array<string, mixed>
     */
    private static function parseAnd(array $tokens, int &$pos): array
    {
        $left = self::parseCmp($tokens, $pos);
        while (self::isOp($tokens, $pos, '&&')) {
            ++$pos;
            $left = ['k' => 'and', 'a' => $left, 'b' => self::parseCmp($tokens, $pos)];
        }

        return $left;
    }

    /**
     * @param list<array{t: string, v: string|float}> $tokens
     *
     * @return array<string, mixed>
     */
    private static function parseCmp(array $tokens, int &$pos): array
    {
        $left = self::parseAdd($tokens, $pos);
        foreach (['=', '!=', '<=', '>=', '<', '>'] as $op) {
            if (self::isOp($tokens, $pos, $op)) {
                ++$pos;

                return ['k' => 'cmp', 'op' => $op, 'a' => $left, 'b' => self::parseAdd($tokens, $pos)];
            }
        }

        return $left;
    }

    /**
     * @param list<array{t: string, v: string|float}> $tokens
     *
     * @return array<string, mixed>
     */
    private static function parseAdd(array $tokens, int &$pos): array
    {
        $left = self::parseMul($tokens, $pos);
        while (self::isOp($tokens, $pos, '+') || self::isOp($tokens, $pos, '-')) {
            $op = (string) $tokens[$pos]['v'];
            ++$pos;
            $left = ['k' => 'bin', 'op' => $op, 'a' => $left, 'b' => self::parseMul($tokens, $pos)];
        }

        return $left;
    }

    /**
     * @param list<array{t: string, v: string|float}> $tokens
     *
     * @return array<string, mixed>
     */
    private static function parseMul(array $tokens, int &$pos): array
    {
        $left = self::parseUnary($tokens, $pos);
        while (self::isOp($tokens, $pos, '*') || self::isOp($tokens, $pos, '/')) {
            $op = (string) $tokens[$pos]['v'];
            ++$pos;
            $left = ['k' => 'bin', 'op' => $op, 'a' => $left, 'b' => self::parseUnary($tokens, $pos)];
        }

        return $left;
    }

    /**
     * @param list<array{t: string, v: string|float}> $tokens
     *
     * @return array<string, mixed>
     */
    private static function parseUnary(array $tokens, int &$pos): array
    {
        if (self::isOp($tokens, $pos, '-')) {
            ++$pos;

            return ['k' => 'neg', 'a' => self::parseUnary($tokens, $pos)];
        }
        if (self::isOp($tokens, $pos, '!')) {
            ++$pos;

            return ['k' => 'not', 'a' => self::parseUnary($tokens, $pos)];
        }

        return self::parsePrimary($tokens, $pos);
    }

    /**
     * @param list<array{t: string, v: string|float}> $tokens
     *
     * @return array<string, mixed>
     */
    private static function parsePrimary(array $tokens, int &$pos): array
    {
        $tok = $tokens[$pos] ?? throw new \InvalidArgumentException('end');
        ++$pos;

        switch ($tok['t']) {
            case 'num':
                return ['k' => 'num', 'v' => $tok['v']];
            case 'str':
                return ['k' => 'str', 'v' => $tok['v']];
            case 'ref':
                return ['k' => 'ref', 'v' => $tok['v']];
            case 'name':
                if ($tok['v'] === 'true' || $tok['v'] === 'false') {
                    return ['k' => 'bool', 'v' => $tok['v'] === 'true'];
                }
                if (!in_array($tok['v'], self::FUNCTIONS, true) || !self::isOp($tokens, $pos, '(')) {
                    throw new \InvalidArgumentException('function');
                }
                ++$pos;
                $args = [];
                if (!self::isOp($tokens, $pos, ')')) {
                    $args[] = self::parseOr($tokens, $pos);
                    while (self::isOp($tokens, $pos, ';')) {
                        ++$pos;
                        $args[] = self::parseOr($tokens, $pos);
                    }
                }
                if (!self::isOp($tokens, $pos, ')')) {
                    throw new \InvalidArgumentException('parenthesis');
                }
                ++$pos;

                return ['k' => 'call', 'name' => $tok['v'], 'args' => $args];
            default:
                if ($tok['v'] === '(') {
                    $inner = self::parseOr($tokens, $pos);
                    if (!self::isOp($tokens, $pos, ')')) {
                        throw new \InvalidArgumentException('parenthesis');
                    }
                    ++$pos;

                    return $inner;
                }
                throw new \InvalidArgumentException('unexpected');
        }
    }

    /**
     * @param list<array{t: string, v: string|float}> $tokens
     */
    private static function isOp(array $tokens, int $pos, string $op): bool
    {
        return isset($tokens[$pos]) && $tokens[$pos]['t'] === 'op' && $tokens[$pos]['v'] === $op;
    }

    /**
     * @param array<string, mixed> $ast
     * @param list<string>         $out
     */
    private static function collect(array $ast, array &$out): void
    {
        if (($ast['k'] ?? '') === 'ref') {
            $out[] = FormDefinition::str($ast['v'] ?? '');
        }
        foreach (['a', 'b'] as $side) {
            if (is_array($ast[$side] ?? null)) {
                /** @var array<string, mixed> $child */
                $child = $ast[$side];
                self::collect($child, $out);
            }
        }
        foreach (FormDefinition::listOf($ast['args'] ?? []) as $arg) {
            self::collect($arg, $out);
        }
    }

    // ── Evaluator ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>    $n
     * @param callable(string): mixed $resolve
     */
    private static function ev(array $n, callable $resolve): mixed
    {
        switch ($n['k'] ?? '') {
            case 'num':
            case 'str':
            case 'bool':
                return $n['v'];
            case 'ref':
                $value = $resolve(FormDefinition::str($n['v'] ?? ''));

                return is_array($value) || is_bool($value) || is_string($value) || is_int($value) || is_float($value) ? $value : null;
            case 'neg':
                $a = self::num(self::sub($n, 'a', $resolve));

                return $a === null ? null : -$a;
            case 'not':
                return !self::truthy(self::sub($n, 'a', $resolve));
            case 'and':
                return self::truthy(self::sub($n, 'a', $resolve)) && self::truthy(self::sub($n, 'b', $resolve));
            case 'or':
                return self::truthy(self::sub($n, 'a', $resolve)) || self::truthy(self::sub($n, 'b', $resolve));
            case 'bin':
                $a = self::num(self::sub($n, 'a', $resolve));
                $b = self::num(self::sub($n, 'b', $resolve));
                if ($a === null || $b === null) {
                    return null;
                }

                return match ($n['op']) {
                    '+' => $a + $b,
                    '-' => $a - $b,
                    '*' => $a * $b,
                    default => $b == 0 ? null : $a / $b,
                };
            case 'cmp':
                return self::compare(FormDefinition::str($n['op'] ?? ''), self::sub($n, 'a', $resolve), self::sub($n, 'b', $resolve));
            case 'call':
                return self::call(FormDefinition::str($n['name'] ?? ''), FormDefinition::listOf($n['args'] ?? []), $resolve);
        }

        return null;
    }

    /**
     * @param array<string, mixed>    $n
     * @param callable(string): mixed $resolve
     */
    private static function sub(array $n, string $side, callable $resolve): mixed
    {
        $child = FormDefinition::map($n[$side] ?? null);

        return $child === null ? null : self::ev($child, $resolve);
    }

    /** A number out of a value (a numeric text counts), or null. */
    private static function num(mixed $v): int|float|null
    {
        if (is_bool($v) || is_array($v) || $v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? $v + 0 : null;
    }

    private static function truthy(mixed $v): bool
    {
        if (is_array($v)) {
            return $v !== [];
        }

        return $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0;
    }

    private static function compare(string $op, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null || is_array($a) || is_array($b)) {
            return false;
        }
        $x = self::num($a);
        $y = self::num($b);
        if ($x !== null && $y !== null) {
            $cmp = $x <=> $y;
        } elseif (is_bool($a) || is_bool($b)) {
            // Yes/no values only tell equal from different.
            if ($op !== '=' && $op !== '!=') {
                return false;
            }
            $cmp = $a === $b ? 0 : 1;
        } else {
            $cmp = strcmp(FormDefinition::str($a), FormDefinition::str($b));
        }

        return match ($op) {
            '='  => $cmp === 0,
            '!=' => $cmp !== 0,
            '<'  => $cmp < 0,
            '<=' => $cmp <= 0,
            '>'  => $cmp > 0,
            default => $cmp >= 0,
        };
    }

    /**
     * @param list<array<string, mixed>> $args
     * @param callable(string): mixed    $resolve
     */
    private static function call(string $name, array $args, callable $resolve): mixed
    {
        $arg = static fn (int $i): mixed => isset($args[$i]) ? self::ev($args[$i], $resolve) : null;

        switch ($name) {
            case 'if':
                return self::truthy($arg(0)) ? $arg(1) : $arg(2);
            case 'round':
                $x = self::num($arg(0));

                return $x === null ? null : round((float) $x, max(0, min(8, (int) (self::num($arg(1)) ?? 0))));
            case 'abs':
                $x = self::num($arg(0));

                return $x === null ? null : abs($x);
            case 'empty':
                $v = $arg(0);

                return $v === null || $v === '' || $v === [];
            case 'count_if':
            case 'pct_if':
                $list  = self::items($arg(0));
                $op    = FormDefinition::str($arg(1));
                $wanted = $arg(2);
                if ($list === []) {
                    return $name === 'count_if' ? 0 : null;
                }
                $hits = count(array_filter($list, static fn ($item): bool => self::compare($op, $item, $wanted)));

                return $name === 'count_if' ? $hits : $hits / count($list) * 100;
        }

        // sum, avg, min, max and count take any number of lists and values and read them as one list.
        $all = [];
        foreach (array_keys($args) as $i) {
            array_push($all, ...self::items($arg($i)));
        }
        $values = array_values(array_filter($all, static fn ($v): bool => $v !== null && $v !== ''));
        if ($name === 'count') {
            return count($values);
        }
        $numbers = array_values(array_filter(array_map(self::num(...), $values), static fn ($v): bool => $v !== null));
        if ($numbers === []) {
            return $name === 'sum' ? 0 : null;
        }

        return match ($name) {
            'sum' => array_sum($numbers),
            'avg' => array_sum($numbers) / count($numbers),
            'min' => min($numbers),
            default => max($numbers),
        };
    }

    /** @return list<mixed> */
    private static function items(mixed $v): array
    {
        return is_array($v) ? array_values($v) : ($v === null ? [] : [$v]);
    }
}
