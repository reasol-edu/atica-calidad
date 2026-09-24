<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Indicator;
use App\Entity\IndicatorStatus;
use App\Service\MeasurementCalendarTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** How a value stands against the year's target, how it's written, and the calendar templates. */
final class IndicatorTest extends TestCase
{
    private function indicator(bool $higherIsBetter, ?string $unit = '%'): Indicator
    {
        return (new Indicator(new EducationalCentre(), 'Indicador', new \DateTimeImmutable()))->setHigherIsBetter($higherIsBetter)->setUnit($unit);
    }

    /** @var array<string, AcademicYear> one per name, with an id as if saved */
    private array $years = [];

    private function year(string $name = '2026-2027'): AcademicYear
    {
        if (!isset($this->years[$name])) {
            $year = (new AcademicYear())->setName($name);
            (new \ReflectionProperty(AcademicYear::class, 'id'))->setValue($year, Uuid::v7());
            $this->years[$name] = $year;
        }

        return $this->years[$name];
    }

    /** @return iterable<string, array{bool, ?float, ?float, float, IndicatorStatus}> */
    public static function statuses(): iterable
    {
        // More is better: target 85, alert from 80.
        yield 'higher, reaching the target' => [true, 85, 80, 85, IndicatorStatus::OnTarget];
        yield 'higher, between threshold and target' => [true, 85, 80, 82, IndicatorStatus::Alert];
        yield 'higher, at the threshold' => [true, 85, 80, 80, IndicatorStatus::Alert];
        yield 'higher, past the threshold' => [true, 85, 80, 79.9, IndicatorStatus::OffTarget];
        // Less is better: target 5, alert up to 7.
        yield 'lower, under the target' => [false, 5, 7, 4.2, IndicatorStatus::OnTarget];
        yield 'lower, between target and threshold' => [false, 5, 7, 6, IndicatorStatus::Alert];
        yield 'lower, past the threshold' => [false, 5, 7, 7.6, IndicatorStatus::OffTarget];
        // No threshold: short of the target is off target; no target, nothing to compare with.
        yield 'no threshold' => [true, 85, null, 84, IndicatorStatus::OffTarget];
        yield 'no target' => [true, null, null, 12, IndicatorStatus::OnTarget];
    }

    #[DataProvider('statuses')]
    public function testStatusAgainstTheYearsTarget(bool $higher, ?float $target, ?float $threshold, float $value, IndicatorStatus $expected): void
    {
        $indicator = $this->indicator($higher);
        $indicator->targetForOrNew($this->year())->setGoals($target, $threshold);

        self::assertSame($expected, $indicator->targetForOrNew($this->year())->statusOf($value));
    }

    public function testATargetIsPerYear(): void
    {
        $indicator = $this->indicator(true);
        $year      = $this->year();
        $target    = $indicator->targetForOrNew($year);

        self::assertSame($target, $indicator->targetForOrNew($year));
        self::assertCount(1, $indicator->getTargets());
        self::assertNull($indicator->targetFor($this->year('2025-2026')));
    }

    public function testValuesAreWrittenTheSpanishWay(): void
    {
        self::assertSame('87,5 %', $this->indicator(true)->format(87.5));
        self::assertSame('100 %', $this->indicator(true)->format(100));
        self::assertSame('1.200 días', $this->indicator(true, 'días')->format(1200));
        self::assertSame('7,25', $this->indicator(true, null)->format(7.25));
        self::assertSame('—', $this->indicator(true)->format(null));
        self::assertSame('0', Indicator::number(0));
        self::assertSame('10', Indicator::number(10.0));
    }

    public function testTemplatesTakeTheYearFromItsName(): void
    {
        $templates = new MeasurementCalendarTemplates();
        $today     = new \DateTimeImmutable('2026-10-01');

        $evaluations = $templates->periods('evaluations', $this->year('2026-2027'), $today);
        self::assertSame(['1.ª evaluación', '2.ª evaluación', '3.ª evaluación', 'Final 1', 'Final 2'], array_column($evaluations, 'name'));
        self::assertSame('2026-09-15', $evaluations[0]['start']->format('Y-m-d'));
        self::assertSame('2027-06-30', $evaluations[4]['end']->format('Y-m-d'));

        $monthly = $templates->periods('monthly', $this->year('2026-2027'), $today);
        self::assertCount(10, $monthly);
        self::assertSame(['Septiembre', '2026-09-30'], [$monthly[0]['name'], $monthly[0]['end']->format('Y-m-d')]);
        self::assertSame(['Febrero', '2027-02-28'], [$monthly[5]['name'], $monthly[5]['end']->format('Y-m-d')]);

        // A name without a year: the current one, from September.
        self::assertSame(2026, MeasurementCalendarTemplates::firstYear($this->year('Curso actual'), $today));
        self::assertSame(2025, MeasurementCalendarTemplates::firstYear($this->year('Curso actual'), new \DateTimeImmutable('2026-03-01')));
    }
}
