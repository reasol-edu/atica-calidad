<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingOrigin;
use App\Entity\Indicator;
use App\Entity\IndicatorStatus;
use App\Entity\Measurement;
use App\Entity\MeasurementCalendar;
use App\Entity\MeasurementPeriod;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\IndicatorRepository;
use App\Repository\MeasurementCalendarRepository;
use App\Repository\MeasurementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything that changes indicators: their measurement calendars, the indicators themselves and
 * their yearly targets, the values recorded, and what's made of a value off target — a
 * nonconformity, an improvement action (see FindingService::createPlanAction()) or nothing. Each
 * method flushes; permission checks are the callers' (QualityVoter).
 */
final class IndicatorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly MeasurementCalendarTemplates $templates,
        private readonly MeasurementCalendarRepository $calendars,
        private readonly IndicatorRepository $indicators,
        private readonly MeasurementRepository $measurements,
        private readonly FindingService $findings,
        private readonly QualityNotifier $notifier,
        private readonly ActivityLogger $activityLogger,
        private readonly TranslatorInterface $translator,
    ) {}

    // ── Measurement calendars ────────────────────────────────────────────────

    /** A calendar for $year, with the periods of template $templateKey (MeasurementCalendarTemplates::KEYS), or none. */
    public function createCalendar(EducationalCentre $centre, AcademicYear $year, string $name, ?string $templateKey): MeasurementCalendar
    {
        $calendar = new MeasurementCalendar($centre, $year, trim($name));
        if ($templateKey !== null) {
            foreach ($this->templates->periods($templateKey, $year, $this->clock->now()) as $p) {
                $calendar->addPeriod($p['name'], $p['start'], $p['end']);
            }
        }
        $this->em->persist($calendar);
        $this->em->flush();

        $this->activityLogger->record('measurement_calendar.create', ['calendar' => $calendar->getName()], $centre);

        return $calendar;
    }

    /**
     * Renames the calendar and replaces its periods with $periods, kept in order of start date. A
     * period given with the id of one of its own keeps it (and its values); one missing from
     * $periods is deleted, with its values.
     *
     * @param list<array{id: ?string, name: string, start: \DateTimeImmutable, end: \DateTimeImmutable}> $periods
     */
    public function saveCalendar(MeasurementCalendar $calendar, string $name, array $periods): void
    {
        $calendar->setName(trim($name));
        usort($periods, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        $existing = [];
        foreach ($calendar->getPeriods() as $period) {
            $existing[$period->getId()->toRfc4122()] = $period;
        }
        $kept = [];
        foreach ($periods as $position => $p) {
            $period = $p['id'] !== null ? ($existing[$p['id']] ?? null) : null;
            if ($period !== null) {
                $period->update(trim($p['name']), $p['start'], $p['end'], $position);
                $kept[$p['id']] = true;
            } else {
                $calendar->addPeriod(trim($p['name']), $p['start'], $p['end'])->update(trim($p['name']), $p['start'], $p['end'], $position);
            }
        }
        foreach ($existing as $id => $period) {
            if (!isset($kept[$id])) {
                $calendar->removePeriod($period);
            }
        }
        $this->em->flush();

        $this->activityLogger->record('measurement_calendar.update', ['calendar' => $calendar->getName()], $calendar->getEducationalCentre());
    }

    public function deleteCalendar(MeasurementCalendar $calendar): void
    {
        $name   = $calendar->getName();
        $centre = $calendar->getEducationalCentre();
        $this->em->remove($calendar);
        $this->em->flush();

        $this->activityLogger->record('measurement_calendar.delete', ['calendar' => $name], $centre);
    }

    /**
     * Prepares $to from $from: copies $from's calendars not already in $to (by name), their dates
     * moved on by as many years as separate the two, and gives each active indicator with a target
     * in $from but none in $to the same target, threshold and (copied) calendar.
     *
     * @return array{calendars: int, targets: int}
     */
    public function copyYear(EducationalCentre $centre, AcademicYear $from, AcademicYear $to): array
    {
        $now   = $this->clock->now();
        $shift = MeasurementCalendarTemplates::firstYear($to, $now) - MeasurementCalendarTemplates::firstYear($from, $now);
        $move  = static fn (\DateTimeImmutable $d): \DateTimeImmutable => $shift === 0 ? $d : $d->modify(($shift > 0 ? '+' : '') . $shift . ' years');

        $byName = [];
        foreach ($this->calendars->findByYear($to) as $calendar) {
            $byName[$calendar->getName()] = $calendar;
        }
        $copies = [];
        $copied = 0;
        foreach ($this->calendars->findByYear($from) as $calendar) {
            if (!isset($byName[$calendar->getName()])) {
                $copy = new MeasurementCalendar($centre, $to, $calendar->getName());
                foreach ($calendar->getPeriods() as $period) {
                    $copy->addPeriod($period->getName(), $move($period->getStartDate()), $move($period->getEndDate()));
                }
                $this->em->persist($copy);
                $byName[$calendar->getName()] = $copy;
                ++$copied;
            }
            $copies[$calendar->getId()->toRfc4122()] = $byName[$calendar->getName()];
        }

        $targets = 0;
        foreach ($this->indicators->findByCentre($centre, activeOnly: true) as $indicator) {
            $old = $indicator->targetFor($from);
            if ($old === null || $indicator->targetFor($to) !== null) {
                continue;
            }
            $indicator->targetForOrNew($to)
                ->setCalendar($old->getCalendar() !== null ? ($copies[$old->getCalendar()->getId()->toRfc4122()] ?? null) : null)
                ->setGoals($old->getTarget(), $old->getAlertThreshold());
            ++$targets;
        }
        $this->em->flush();

        $this->activityLogger->record('measurement_calendar.copy_year', ['from' => $from->getName(), 'to' => $to->getName()], $centre);

        return ['calendars' => $copied, 'targets' => $targets];
    }

    // ── Indicators ───────────────────────────────────────────────────────────

    /**
     * Creates or updates an indicator ($indicator null: a new one), and its target for $year.
     *
     * @param array{name: string, description: ?string, section: ?DocumentSection, unit: ?string, higherIsBetter: bool, teacher: ?Teacher, profile: ?SpecificProfile, active: bool, calendar: ?MeasurementCalendar, target: ?float, alertThreshold: ?float} $data
     */
    public function saveIndicator(EducationalCentre $centre, ?Indicator $indicator, AcademicYear $year, array $data): Indicator
    {
        $isNew     = $indicator === null;
        $indicator ??= new Indicator($centre, trim($data['name']), $this->clock->now());
        $indicator->setName(trim($data['name']))
            ->setDescription(self::nullIfBlank($data['description']))
            ->setSection($data['section'])
            ->setUnit(self::nullIfBlank($data['unit']))
            ->setHigherIsBetter($data['higherIsBetter'])
            ->assignTo($data['teacher'], $data['profile'])
            ->setActive($data['active']);
        $indicator->targetForOrNew($year)
            ->setCalendar($data['calendar'])
            ->setGoals($data['target'], $data['alertThreshold']);
        $this->em->persist($indicator);
        $this->em->flush();

        $this->activityLogger->record($isNew ? 'indicator.create' : 'indicator.update', ['indicator' => $indicator->getName()], $centre);

        return $indicator;
    }

    // ── Measurements ─────────────────────────────────────────────────────────

    /**
     * Records $indicator's value for $period, or corrects it. A value that comes out off target
     * (and wasn't already dealt with) tells the quality managers.
     */
    public function record(Indicator $indicator, MeasurementPeriod $period, float $value, ?string $notes, Teacher $actor): Measurement
    {
        $now         = $this->clock->now();
        $measurement = $this->measurements->findOneByIndicatorAndPeriod($indicator, $period);
        if ($measurement === null) {
            $measurement = new Measurement($indicator, $period, $value, self::nullIfBlank($notes), $actor, $now);
            $this->em->persist($measurement);
        } else {
            $measurement->correct($value, self::nullIfBlank($notes), $actor, $now);
        }
        $this->em->flush();

        $this->activityLogger->record('measurement.record', [
            'indicator' => $indicator->getName(),
            'period'    => $period->getName(),
            'value'     => $indicator->format($value),
        ], $indicator->getEducationalCentre());

        if ($measurement->status() === IndicatorStatus::OffTarget && !$measurement->isReviewed()) {
            $this->notifier->measurementOffTarget($measurement);
        }

        return $measurement;
    }

    /** An off-target value that needs nothing done, and why. */
    public function dismiss(Measurement $measurement, Teacher $actor, string $note): void
    {
        $measurement->markReviewed($actor, $this->clock->now(), trim($note));
        $this->em->flush();

        $this->activityLogger->record('measurement.dismiss', [
            'indicator' => $measurement->getIndicator()->getName(),
            'period'    => $measurement->getPeriod()->getName(),
        ], $measurement->getIndicator()->getEducationalCentre());
    }

    /**
     * Opens a finding from an off-target value, as if reported by $actor: in the inbox to be
     * classified, its origin "indicator", linked to the value.
     */
    public function openFinding(Measurement $measurement, Teacher $actor): Finding
    {
        $indicator = $measurement->getIndicator();
        $target    = $indicator->targetFor($measurement->getPeriod()->getCalendar()->getAcademicYear());
        $finding   = $this->findings->report(
            $indicator->getEducationalCentre(),
            $actor,
            $this->translator->trans('indicator.finding_description', [
                '%indicator%' => $indicator->getName(),
                '%period%'    => $measurement->getPeriod()->getName(),
                '%value%'     => $indicator->format($measurement->getValue()),
                '%target%'    => $indicator->format($target?->getTarget()),
            ], 'quality'),
            $indicator->getSection(),
        );
        $finding->setOrigin(FindingOrigin::Indicator)->setMeasurement($measurement);
        $measurement->markReviewed($actor, $this->clock->now());
        $this->em->flush();

        return $finding;
    }

    private static function nullIfBlank(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
