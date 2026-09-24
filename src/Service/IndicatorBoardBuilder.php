<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Indicator;
use App\Entity\Measurement;
use App\Model\IndicatorCell;
use App\Model\IndicatorRow;
use App\Repository\AcademicYearRepository;
use App\Repository\IndicatorRepository;
use App\Repository\MeasurementRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The indicators of one academic year as the board, an indicator's page and the report show them
 * (IndicatorRow): every period with its value or why it's missing, the latest value and its
 * status, the year before's comparable value, and a small chart — this year's line, last year's,
 * the target and the alert threshold, scaled to a 100 × 40 box.
 */
final class IndicatorBoardBuilder
{
    private const float WIDTH  = 100.0;
    private const float HEIGHT = 40.0;
    private const float MARGIN = 4.0;

    public function __construct(
        private readonly IndicatorRepository $indicators,
        private readonly MeasurementRepository $measurements,
        private readonly AcademicYearRepository $years,
        private readonly AppSettingsInterface $settings,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * The centre's active indicators with a target in $year, grouped by process (its name, or ''
     * for none), processes and indicators by name.
     *
     * @return array<string, list<IndicatorRow>>
     */
    public function board(EducationalCentre $centre, AcademicYear $year): array
    {
        $measurements = $this->measurements->findByCentreIndexed($centre);
        $previousYear = $this->previousYear($centre, $year);

        $groups = [];
        foreach ($this->indicators->findByCentre($centre, activeOnly: true) as $indicator) {
            if ($indicator->targetFor($year) === null) {
                continue;
            }
            $groups[$indicator->getSection()?->getName() ?? ''][] = $this->buildRow($indicator, $year, $previousYear, $measurements);
        }
        uksort($groups, static fn (string $a, string $b): int => ($a === '') <=> ($b === '') ?: strnatcasecmp($a, $b));

        return $groups;
    }

    public function row(Indicator $indicator, AcademicYear $year): IndicatorRow
    {
        return $this->buildRow($indicator, $year, $this->previousYear($indicator->getEducationalCentre(), $year), $this->measurements->findByCentreIndexed($indicator->getEducationalCentre()));
    }

    /** The centre's academic year just before $year (by name — they're listed newest first), if any. */
    public function previousYear(EducationalCentre $centre, AcademicYear $year): ?AcademicYear
    {
        $found = false;
        foreach ($this->years->findByCentreOrderedByName($centre) as $candidate) {
            if ($found) {
                return $candidate;
            }
            $found = $candidate->getId()->equals($year->getId());
        }

        return null;
    }

    /** @param array<string, array<string, Measurement>> $measurements */
    private function buildRow(Indicator $indicator, AcademicYear $year, ?AcademicYear $previousYear, array $measurements): IndicatorRow
    {
        $today  = $this->clock->now()->setTime(0, 0);
        $days   = $this->settings->getForCentre('quality.measurement_days', $indicator->getEducationalCentre());
        $days   = \is_int($days) && $days >= 0 ? $days : 15;
        $mine   = $measurements[$indicator->getId()->toRfc4122()] ?? [];
        $target = $indicator->targetFor($year);

        $cells  = [];
        $latest = null;
        $status = null;
        foreach ($target?->getCalendar()?->getPeriods() ?? [] as $period) {
            $measurement = $mine[$period->getId()->toRfc4122()] ?? null;
            $due         = $period->getEndDate()->modify('+' . $days . ' days');
            $state       = match (true) {
                $measurement !== null      => IndicatorCell::VALUE,
                !$period->hasEnded($today) => IndicatorCell::FUTURE,
                $due < $today              => IndicatorCell::LATE,
                default                    => IndicatorCell::PENDING,
            };
            $cell    = new IndicatorCell($period, $measurement, $state, $due);
            $cells[] = $cell;
            $latest  = $measurement ?? $latest;
            $status  = $cell->status() ?? $status;
        }

        $previousValues = $this->yearValues($indicator, $previousYear, $mine);

        return new IndicatorRow(
            $indicator,
            $target,
            $cells,
            $latest,
            $status,
            $this->comparable($latest, $previousValues, $previousYear),
            $this->chart($cells, $previousValues, $target?->getTarget(), $target?->getAlertThreshold()),
        );
    }

    /**
     * $indicator's values in $year, in period order, keyed by period name.
     *
     * @param array<string, Measurement> $mine
     *
     * @return array<string, float>
     */
    private function yearValues(Indicator $indicator, ?AcademicYear $year, array $mine): array
    {
        $values = [];
        foreach ($year === null ? [] : ($indicator->targetFor($year)?->getCalendar()?->getPeriods() ?? []) as $period) {
            $measurement = $mine[$period->getId()->toRfc4122()] ?? null;
            if ($measurement !== null) {
                $values[$period->getName()] = $measurement->getValue();
            }
        }

        return $values;
    }

    /**
     * The year before's value for the period named as $latest's, or else its last one.
     *
     * @param array<string, float> $previousValues
     *
     * @return array{value: float, label: string}|null
     */
    private function comparable(?Measurement $latest, array $previousValues, ?AcademicYear $previousYear): ?array
    {
        if ($previousValues === [] || $previousYear === null) {
            return null;
        }
        $name = $latest?->getPeriod()->getName();
        if ($name !== null && isset($previousValues[$name])) {
            return ['value' => $previousValues[$name], 'label' => $previousYear->getName() . ' · ' . $name];
        }
        $lastName = array_key_last($previousValues);

        return ['value' => $previousValues[$lastName], 'label' => $previousYear->getName() . ' · ' . $lastName];
    }

    /**
     * @param list<IndicatorCell>  $cells
     * @param array<string, float> $previousValues
     *
     * @return array{points: list<array{x: float, y: float}>, previous: list<array{x: float, y: float}>, targetY: ?float, thresholdY: ?float}
     */
    private function chart(array $cells, array $previousValues, ?float $target, ?float $threshold): array
    {
        $current = [];
        foreach ($cells as $i => $cell) {
            if ($cell->measurement !== null) {
                $current[$i] = $cell->measurement->getValue();
            }
        }
        // Last year's line, placed at this year's period of the same name.
        $previous = [];
        foreach ($cells as $i => $cell) {
            if (isset($previousValues[$cell->period->getName()])) {
                $previous[$i] = $previousValues[$cell->period->getName()];
            }
        }

        $all = [...array_values($current), ...array_values($previous), ...array_filter([$target, $threshold], static fn (?float $v): bool => $v !== null)];
        if ($all === []) {
            return ['points' => [], 'previous' => [], 'targetY' => null, 'thresholdY' => null];
        }
        $min  = min($all);
        $max  = max($all);
        $span = $max - $min ?: 1.0;
        $n    = max(1, \count($cells) - 1);
        $x    = static fn (int $i): float => round(self::MARGIN + $i * (self::WIDTH - 2 * self::MARGIN) / $n, 2);
        $y    = static fn (float $v): float => round(self::HEIGHT - self::MARGIN - ($v - $min) * (self::HEIGHT - 2 * self::MARGIN) / $span, 2);
        return [
            'points'     => self::line($current, $x, $y),
            'previous'   => self::line($previous, $x, $y),
            'targetY'    => $target !== null ? $y($target) : null,
            'thresholdY' => $threshold !== null ? $y($threshold) : null,
        ];
    }

    /**
     * @param array<int, float>       $values by period position
     * @param callable(int): float   $x
     * @param callable(float): float $y
     *
     * @return list<array{x: float, y: float}>
     */
    private static function line(array $values, callable $x, callable $y): array
    {
        $points = [];
        foreach ($values as $i => $value) {
            $points[] = ['x' => $x($i), 'y' => $y($value)];
        }

        return $points;
    }
}
