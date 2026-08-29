<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionTest extends TestCase
{
    private function definition(SettingType $type, string $default = ''): SettingDefinition
    {
        return (new SettingDefinition())->setKey('test.key')->setType($type)->setDefaultValue($default);
    }

    // ── Boolean ──────────────────────────────────────────────────────────────

    public function testBooleanAcceptsOnlyTrueOrFalse(): void
    {
        $def = $this->definition(SettingType::Boolean, 'false');

        self::assertTrue($def->isValueValid('true'));
        self::assertTrue($def->isValueValid('false'));
        self::assertFalse($def->isValueValid('1'));
        self::assertFalse($def->isValueValid('yes'));
    }

    public function testBooleanCastedDefaultValue(): void
    {
        self::assertTrue($this->definition(SettingType::Boolean, 'true')->getCastedDefaultValue());
        self::assertFalse($this->definition(SettingType::Boolean, 'false')->getCastedDefaultValue());
    }

    // ── Integer ──────────────────────────────────────────────────────────────

    public function testIntegerRejectsNonNumericAndDecimalValues(): void
    {
        $def = $this->definition(SettingType::Integer, '0');

        self::assertFalse($def->isValueValid('abc'));
        self::assertFalse($def->isValueValid('1.5'));
        self::assertTrue($def->isValueValid('42'));
        self::assertTrue($def->isValueValid('-3'));
    }

    public function testIntegerRespectsMinAndMax(): void
    {
        $def = $this->definition(SettingType::Integer, '5')->setMinValue(1)->setMaxValue(10);

        self::assertTrue($def->isValueValid('1'));
        self::assertTrue($def->isValueValid('10'));
        self::assertFalse($def->isValueValid('0'));
        self::assertFalse($def->isValueValid('11'));
    }

    public function testIntegerCastedDefaultValue(): void
    {
        self::assertSame(42, $this->definition(SettingType::Integer, '42')->getCastedDefaultValue());
    }

    // ── String / RichText / Pdf (same validation rule) ──────────────────────

    public function testStringEmptyIsAlwaysValidRegardlessOfMinLength(): void
    {
        $def = $this->definition(SettingType::String)->setMinValue(3);

        self::assertTrue($def->isValueValid(''));
    }

    public function testStringRespectsMinAndMaxLength(): void
    {
        $def = $this->definition(SettingType::String)->setMinValue(2)->setMaxValue(4);

        self::assertFalse($def->isValueValid('a'));
        self::assertTrue($def->isValueValid('ab'));
        self::assertTrue($def->isValueValid('abcd'));
        self::assertFalse($def->isValueValid('abcde'));
    }

    public function testStringLengthIsCountedByCharacterNotByte(): void
    {
        $def = $this->definition(SettingType::String)->setMaxValue(3);

        // 'ñññ' is 3 characters but 6 bytes in UTF-8.
        self::assertTrue($def->isValueValid('ñññ'));
    }

    public function testRichTextAndPdfUseTheSameLengthValidationAsString(): void
    {
        $richText = $this->definition(SettingType::RichText)->setMaxValue(2);
        $pdf      = $this->definition(SettingType::Pdf)->setMaxValue(2);

        self::assertFalse($richText->isValueValid('abc'));
        self::assertFalse($pdf->isValueValid('abc'));
    }

    // ── Choice ───────────────────────────────────────────────────────────────

    public function testChoiceOnlyAcceptsListedValues(): void
    {
        $def = $this->definition(SettingType::Choice)->setChoices('a, b, c');

        self::assertTrue($def->isValueValid('a'));
        self::assertTrue($def->isValueValid('b'));
        self::assertFalse($def->isValueValid('d'));
        self::assertFalse($def->isValueValid(''));
    }

    public function testGetChoicesArrayTrimsAndFiltersEmptyEntries(): void
    {
        $def = $this->definition(SettingType::Choice)->setChoices('a, , b ,  ');

        self::assertSame(['a', 'b'], $def->getChoicesArray());
    }

    public function testGetChoicesArrayIsEmptyWhenChoicesIsNull(): void
    {
        $def = $this->definition(SettingType::Choice);

        self::assertSame([], $def->getChoicesArray());
    }
}
