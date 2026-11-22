<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\FormSubmissionRevision;
use App\Entity\IndicatorFormSource;
use App\Entity\Measurement;
use App\Entity\MeasurementPeriod;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Repository\FormValueRepository;
use App\Repository\IndicatorFormSourceRepository;
use App\Repository\MeasurementRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\ActivitySubmissionSlotBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Calculates the measurements of indicators that take their value from delivered documents. Only the latest APPROVED revision of
 * each slot counts. A period's value is recalculated until somebody has decided what to do about it (then it is frozen), and a value
 * typed by hand is never overwritten. Reads only FormValue rows, never whole documents.
 */
final class FormIndicatorCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly IndicatorFormSourceRepository $sources,
        private readonly FormSubmissionRepository $submissions,
        private readonly FormSubmissionRevisionRepository $revisions,
        private readonly FormValueRepository $values,
        private readonly MeasurementRepository $measurements,
        private readonly ActivityDeadlineChecker $deadlines,
        private readonly ActivitySubmissionSlotBuilder $slots,
        private readonly FormListOptions $lists,
    ) {}

    /** Recalculates every period already started of every computed indicator; returns how many measurements were written. */
    public function recalculateAll(): int
    {
        $written = 0;
        foreach ($this->sources->findAll() as $source) {
            $written += $this->recalculate($source);
        }

        return $written;
    }

    /** Recalculates the indicators fed from one activity (when one of its deliveries is approved); returns how many measurements were written. */
    public function recalculateForActivity(\App\Entity\Activity $activity): int
    {
        $written = 0;
        foreach ($this->sources->findByActivity($activity) as $source) {
            $written += $this->recalculate($source);
        }

        return $written;
    }

    public function recalculate(IndicatorFormSource $source): int
    {
        $today   = $this->clock->now()->setTime(0, 0);
        $written = 0;
        foreach ($source->getIndicator()->getTargets() as $target) {
            foreach ($target->getCalendar()?->getPeriods() ?? [] as $period) {
                if ($period->getStartDate() <= $today && $this->recalculatePeriod($source, $period)) {
                    ++$written;
                }
            }
        }

        return $written;
    }

    /** @return bool whether a measurement was written */
    public function recalculatePeriod(IndicatorFormSource $source, MeasurementPeriod $period): bool
    {
        $existing = $this->measurements->findOneByIndicatorAndPeriod($source->getIndicator(), $period);
        if ($existing !== null && (!$existing->isComputed() || $existing->isReviewed())) {
            return false; // typed by hand, or already decided about: frozen
        }

        $result = $this->compute($source, $period);
        if ($result === null) {
            return false;
        }

        $now = $this->clock->now();
        if ($existing === null) {
            $existing = new Measurement($source->getIndicator(), $period, $result['value'], null, null, $now);
            $this->em->persist($existing);
        }
        $existing->setComputed($result['value'], $result['sample'], $result['expected'], $result['groups'], $now);
        $this->em->flush();

        return true;
    }

    /**
     * What each option of a choice field is called, so a group made by it reads "Matemáticas", not an id: as the documents of the
     * period were delivered (what a list-fed field offered was frozen with each one).
     *
     * @param array<string, FormSubmissionRevision> $latest
     *
     * @return array<string, string> label by option key
     */
    private function optionLabels(array $latest, string $field): array
    {
        $labels = [];
        foreach ($latest as $filled) {
            $index = FormIndex::of($this->lists->delivered($filled));
            $spec  = $index->fields[$field] ?? null;
            if ($spec === null) {
                foreach (array_keys($index->tables) as $table) {
                    $spec ??= $index->column($table, $field);
                }
            }
            foreach (FormDefinition::listOf($spec['options'] ?? []) as $option) {
                $labels[FormDefinition::str($option['key'] ?? '')] ??= FormDefinition::str($option['label'] ?? '');
            }
        }

        return $labels;
    }

    /**
     * @return array{value: float, sample: int, expected: int, groups:list<array{key: string, label: string, value: float, count: int}>}|null
     *                                                                                                                                        null while no delivery is approved yet
     */
    public function compute(IndicatorFormSource $source, MeasurementPeriod $period): ?array
    {
        $activity = $source->getActivity();
        $cycle    = $this->deadlines->cycleKeyNear($activity, $period->getEndDate());

        $labels = [];
        foreach ($this->slots->buildSlots($activity) as $slot) {
            $labels[$slot->key()] = $slot->displayName;
        }

        /** @var array<string, FormSubmissionRevision> $latest by revision id */
        $latest = [];
        /** @var array<string, string> $slotOf revision id → slot key */
        $slotOf = [];
        foreach ($this->submissions->findByActivityCycle($activity, $cycle) as $submission) {
            $best = null;
            foreach ($this->revisions->findBySubmission($submission) as $candidate) {
                if ($candidate->getRevision()->isApproved() && ($best === null || $candidate->getRevision()->getVersion() > $best->getRevision()->getVersion())) {
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                $latest[$best->getId()->toRfc4122()] = $best;
                $slotOf[$best->getId()->toRfc4122()] = $submission->getSlotKey();
            }
        }
        if ($latest === []) {
            return null;
        }

        $groupField = str_starts_with($source->getGroupBy(), 'field:') ? substr($source->getGroupBy(), 6) : null;
        $keys       = array_values(array_filter([$source->getFieldKey(), $source->getDenominatorKey(), $groupField]));
        $rows       = array_values(array_filter(
            $this->values->findByActivityCycleAndFields($activity, $cycle, $keys),
            static fn (\App\Entity\FormValue $v): bool => isset($latest[$v->getSubmissionRevision()->getId()->toRfc4122()]),
        ));

        // Where each group field value lives, to tell the group of a value in the same copy (and row).
        $groupOf = [];
        foreach ($rows as $v) {
            if ($v->getFieldKey() === $groupField) {
                $groupOf[$v->getSubmissionRevision()->getId()->toRfc4122() . '|' . $v->getPath() . '|' . ($v->getRowKey() ?? '')] = $v->getText() ?? '';
            }
        }

        /** @var array<string, list<\App\Entity\FormValue>> $numerator */
        $numerator = [];
        /** @var array<string, list<\App\Entity\FormValue>> $denominator */
        $denominator = [];
        foreach ($rows as $v) {
            $rev = $v->getSubmissionRevision()->getId()->toRfc4122();
            $group = match (true) {
                $groupField !== null => $groupOf[$rev . '|' . $v->getPath() . '|' . ($v->getRowKey() ?? '')] ?? $groupOf[$rev . '|' . $v->getPath() . '|'] ?? '',
                $source->getGroupBy() === IndicatorFormSource::GROUP_SLOT => $slotOf[$rev],
                default => '',
            };
            if ($v->getFieldKey() === $source->getFieldKey()) {
                $numerator[$group][] = $v;
            }
            if ($source->getDenominatorKey() !== null && $v->getFieldKey() === $source->getDenominatorKey()) {
                $denominator[$group][] = $v;
            }
        }

        $all    = array_merge(...array_values($numerator ?: [[]]));
        $allDen = array_merge(...array_values($denominator ?: [[]]));
        $value  = $this->aggregate($source, $all, $allDen);
        if ($value === null) {
            return null;
        }

        $groups = [];
        if ($source->getGroupBy() !== IndicatorFormSource::GROUP_NONE) {
            $optionLabels = $groupField === null ? [] : $this->optionLabels($latest, $groupField);
            foreach ($numerator as $group => $list) {
                $groupValue = $this->aggregate($source, $list, $denominator[$group] ?? []);
                if ($groupValue === null) {
                    continue;
                }
                $groups[] = ['key' => (string) $group, 'label' => $source->getGroupBy() === IndicatorFormSource::GROUP_SLOT ? ($labels[$group] ?? $group) : ($group === '' ? '—' : ($optionLabels[$group] ?? $group)), 'value' => $groupValue, 'count' => count($list)];
            }
            usort($groups, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);
        }

        return ['value' => $value, 'sample' => count($latest), 'expected' => max(count($labels), count($latest)), 'groups' => $groups];
    }

    /**
     * @param list<\App\Entity\FormValue> $values
     * @param list<\App\Entity\FormValue> $denominator
     */
    private function aggregate(IndicatorFormSource $source, array $values, array $denominator): ?float
    {
        $nums = array_values(array_filter(array_map(static fn (\App\Entity\FormValue $v): ?float => $v->getNum(), $values), static fn (?float $n): bool => $n !== null));

        if ($source->getDenominatorKey() !== null) {
            $den = array_sum(array_filter(array_map(static fn (\App\Entity\FormValue $v): ?float => $v->getNum(), $denominator), static fn (?float $n): bool => $n !== null));

            return $den == 0 ? null : array_sum($nums) / $den * 100;
        }

        return match ($source->getAggregation()) {
            IndicatorFormSource::SUM => $nums === [] ? null : array_sum($nums),
            IndicatorFormSource::AVG => $nums === [] ? null : array_sum($nums) / count($nums),
            IndicatorFormSource::MIN => $nums === [] ? null : min($nums),
            IndicatorFormSource::MAX => $nums === [] ? null : max($nums),
            IndicatorFormSource::COUNT => (float) count($values),
            IndicatorFormSource::COUNT_IF => (float) count(array_filter($values, fn (\App\Entity\FormValue $v): bool => $this->matches($source, $v))),
            IndicatorFormSource::PERCENT_IF => $values === [] ? null : count(array_filter($values, fn (\App\Entity\FormValue $v): bool => $this->matches($source, $v))) / count($values) * 100,
            default => null,
        };
    }

    private function matches(IndicatorFormSource $source, \App\Entity\FormValue $v): bool
    {
        $expected = (string) $source->getConditionValue();
        $actual   = $v->getNum() ?? $v->getText();
        if ($actual === null) {
            return false;
        }
        $bothNumeric = is_numeric($expected) && (is_float($actual) || is_numeric($actual));
        $left        = $bothNumeric ? (float) $actual : (string) $actual;
        $right       = $bothNumeric ? (float) $expected : $expected;

        return match ($source->getConditionOp()) {
            'ne' => $left != $right,
            'gt' => $left > $right,
            'ge' => $left >= $right,
            'lt' => $left < $right,
            'le' => $left <= $right,
            default => $left == $right,
        };
    }

    /**
     * Sets (or, with a null activity, removes) the computed source of an indicator and calculates it at once.
     */
    public function configure(\App\Entity\Indicator $indicator, ?\App\Entity\Activity $activity, string $fieldKey, string $aggregation, ?string $op, ?string $value, ?string $denominatorKey, string $groupBy): void
    {
        $source = $this->sources->findByIndicator($indicator);
        if ($activity === null || $fieldKey === '') {
            if ($source !== null) {
                $this->em->remove($source);
                $this->em->flush();
            }

            return;
        }
        if ($source === null) {
            $source = new IndicatorFormSource($indicator, $activity, $fieldKey);
            $this->em->persist($source);
        }
        $source->configure($activity, $fieldKey, $aggregation, $op, $value, $denominatorKey, $groupBy);
        $this->em->flush();
        $this->recalculate($source);
    }
}
