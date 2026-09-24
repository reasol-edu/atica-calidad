<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Audit;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingStatus;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionStatus;
use App\Entity\ManagementReview;
use App\Model\ActivityStatusReportRow;
use App\Model\DocumentMasterListRow;
use App\Repository\AuditProgramRepository;
use App\Repository\AuditRepository;
use App\Repository\DocumentRepository;
use App\Repository\FindingRepository;
use App\Repository\ImprovementActionRepository;
use App\Repository\ManagementReviewRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Compiles what the application knows of a management review's inputs (ISO 9001 9.3.2) for its
 * period: how the previous review's decisions went, the indicators against their targets, the
 * findings (nonconformities, complaints, improvement opportunities) and how effective their
 * actions were, the internal audits, the improvement plan, the activities and the documents.
 *
 * The result is plain data (strings, numbers, booleans, lists) so that closing the review can
 * freeze it as its snapshot and the page and the PDF show a closed review exactly as it was.
 * Dates are "Y-m-d"; statuses are the enums' values.
 */
final class ManagementReviewBuilder
{
    /** Version of the snapshot's shape, should it ever change. */
    public const int VERSION = 1;

    public function __construct(
        private readonly ManagementReviewRepository $reviews,
        private readonly ImprovementActionRepository $actions,
        private readonly FindingRepository $findings,
        private readonly AuditRepository $audits,
        private readonly AuditProgramRepository $programs,
        private readonly IndicatorBoardBuilder $indicators,
        private readonly ActivityStatusReportBuilder $activities,
        private readonly DocumentMasterListBuilder $documents,
        private readonly DocumentRepository $documentRepository,
        private readonly ReadAcknowledgementService $readAcknowledgements,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Its inputs: the frozen snapshot of a closed review, or compiled now for a draft.
     *
     * @return array<string, mixed>
     */
    public function inputsOf(ManagementReview $review): array
    {
        return $review->getSnapshot() ?? $this->build($review);
    }

    /** @return array<string, mixed> */
    public function build(ManagementReview $review): array
    {
        $today = $this->clock->now()->setTime(0, 0);

        return [
            'version'         => self::VERSION,
            'compiledAt'      => $this->clock->now()->format('Y-m-d H:i'),
            'previousActions' => $this->previousActions($review, $today),
            'indicators'      => $this->indicatorInputs($review),
            'findings'        => $this->findingInputs($review),
            'audits'          => $this->auditInputs($review),
            'plan'            => $this->planInputs($review, $today),
            'activities'      => $this->activityInputs($review),
            'documents'       => $this->documentInputs($review),
        ];
    }

    /**
     * The default period of a new review: from the day after the previous review's period, or
     * else the start of the academic year (1 September), to today.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function defaultPeriod(EducationalCentre $centre): array
    {
        $today  = $this->clock->now()->setTime(0, 0);
        $latest = $this->reviews->findLatest($centre);
        if ($latest !== null && $latest->getPeriodEnd() < $today) {
            return [$latest->getPeriodEnd()->modify('+1 day'), $today];
        }
        $year = (int) $today->format('n') >= 9 ? (int) $today->format('Y') : (int) $today->format('Y') - 1;

        return [new \DateTimeImmutable($year . '-09-01'), $today];
    }

    /**
     * 9.3.2 a: what became of the previous review's decisions.
     *
     * @return array<string, mixed>
     */
    private function previousActions(ManagementReview $review, \DateTimeImmutable $today): array
    {
        $previous = $this->reviews->findPrevious($review);
        if ($previous === null) {
            return ['review' => null, 'heldOn' => null, 'items' => []];
        }

        return [
            'review' => $previous->getTitle(),
            'heldOn' => $previous->getHeldOn()->format('Y-m-d'),
            'items'  => array_map(fn (ImprovementAction $a): array => $this->action($a, $today), $this->actions->findByManagementReview($previous)),
        ];
    }

    /**
     * 9.3.2 c 3: the year's indicators against their targets.
     *
     * @return array<string, mixed>
     */
    private function indicatorInputs(ManagementReview $review): array
    {
        $counts = ['on_target' => 0, 'alert' => 0, 'off_target' => 0, 'no_data' => 0, 'none' => 0];
        $rows   = [];
        foreach ($this->indicators->board($review->getEducationalCentre(), $review->getAcademicYear()) as $process => $group) {
            foreach ($group as $row) {
                $indicator = $row->indicator;
                $status    = $row->status?->value;
                ++$counts[$status ?? 'none'];
                $rows[] = [
                    'process'        => $process,
                    'name'           => $indicator->getName(),
                    'higherIsBetter' => $indicator->isHigherBetter(),
                    'target'         => $row->target?->getTarget() === null ? null : $indicator->format($row->target->getTarget()),
                    'latest'         => $row->latest === null ? null : $indicator->format($row->latest->getValue()),
                    'latestPeriod'   => $row->latest?->getPeriod()->getName(),
                    'status'         => $status,
                    'previous'       => $row->previous === null ? null : $indicator->format($row->previous['value']),
                    'measured'       => $row->measuredCount(),
                    'periods'        => \count($row->cells),
                ];
            }
        }

        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * 9.3.2 c 2 and c 4: complaints and other feedback, nonconformities and corrective actions,
     * and the improvement opportunities raised (10.1).
     *
     * @return array<string, mixed>
     */
    private function findingInputs(ManagementReview $review): array
    {
        $from = $review->getPeriodStart();
        $to   = $review->getPeriodEnd()->modify('+1 day');

        $byKind        = ['nonconformity' => 0, 'observation' => 0, 'improvement_opportunity' => 0, 'unclassified' => 0, 'discarded' => 0];
        $byOrigin      = [];
        $reported      = 0;
        $effective     = 0;
        $ineffective   = 0;
        $open          = [];
        $complaints    = [];
        $opportunities = [];
        foreach ($this->findings->findForReview($review->getEducationalCentre(), $from, $review->getPeriodEnd()) as $finding) {
            $inPeriod = $finding->getReportedAt() >= $from && $finding->getReportedAt() < $to;
            if ($inPeriod) {
                ++$reported;
                $kind = match (true) {
                    $finding->getStatus() === FindingStatus::Discarded => 'discarded',
                    $finding->getKind() === null                       => 'unclassified',
                    default                                            => $finding->getKind()->value,
                };
                ++$byKind[$kind];
                $byOrigin[$finding->getOrigin()->value] = ($byOrigin[$finding->getOrigin()->value] ?? 0) + 1;
                if ($finding->getOrigin() === FindingOrigin::Complaint) {
                    $complaints[] = $this->finding($finding);
                }
                if ($finding->getKind() === FindingKind::ImprovementOpportunity) {
                    $opportunities[] = $this->finding($finding);
                }
            }
            $verifiedAt = $finding->getVerifiedAt();
            if ($verifiedAt !== null && $verifiedAt >= $from && $verifiedAt < $to && $finding->isEffective() !== null) {
                $finding->isEffective() ? ++$effective : ++$ineffective;
            }
            if ($finding->isNonconformity() && $finding->getStatus()->isOpen()) {
                $open[] = $this->finding($finding);
            }
        }
        arsort($byOrigin);

        return [
            'reported'            => $reported,
            'byKind'              => $byKind,
            'byOrigin'            => $byOrigin,
            'effective'           => $effective,
            'ineffective'         => $ineffective,
            'openNonconformities' => $open,
            'complaints'          => $complaints,
            'opportunities'       => $opportunities,
        ];
    }

    /**
     * 9.3.2 c 6: the internal audits of the period.
     *
     * @return array<string, mixed>
     */
    private function auditInputs(ManagementReview $review): array
    {
        $program = $this->programs->findByYear($review->getAcademicYear());
        $items   = [];
        foreach ($this->audits->findPlannedBetween($review->getEducationalCentre(), $review->getPeriodStart(), $review->getPeriodEnd()) as $audit) {
            $items[] = $this->audit($audit);
        }

        return [
            'programApproved' => $program?->isApproved() ?? false,
            'planned'         => $program === null ? 0 : \count($program->getAudits()),
            'items'           => $items,
        ];
    }

    /**
     * 9.3.2 e and 10.3: how the year's improvement plan is going.
     *
     * @return array<string, mixed>
     */
    private function planInputs(ManagementReview $review, \DateTimeImmutable $today): array
    {
        $counts  = ['pending' => 0, 'in_progress' => 0, 'done' => 0, 'overdue' => 0];
        $overdue = [];
        $plan    = $this->actions->findPlan($review->getEducationalCentre(), $review->getAcademicYear());
        foreach ($plan as $action) {
            ++$counts[$action->getStatus()->value];
            if ($action->isOverdue($today)) {
                ++$counts['overdue'];
                $overdue[] = $this->action($action, $today);
            }
        }

        return ['total' => \count($plan), 'counts' => $counts, 'overdue' => $overdue];
    }

    /**
     * 9.3.2 c 3 (process performance): the activities of the year so far, only for the active
     * academic year — the application only knows the current occurrence of each one.
     *
     * @return array<string, mixed>|null
     */
    private function activityInputs(ManagementReview $review): ?array
    {
        $centre = $review->getEducationalCentre();
        if ($centre->getActiveAcademicYear()?->getId()->equals($review->getAcademicYear()->getId()) !== true) {
            return null;
        }

        $end      = $review->getPeriodEnd()->setTime(23, 59, 59);
        $expected = 0;
        $done     = 0;
        $due      = 0;
        $late     = [];
        foreach ($this->activities->build($centre) as $row) {
            if ($row->deadline > $end) {
                continue;
            }
            ++$due;
            $expected += $row->expected;
            $done     += $row->done;
            if ($row->donePercentage() < 100 && $row->expected > 0) {
                $late[] = $this->activity($row);
            }
        }

        return [
            'due'        => $due,
            'percentage' => $expected === 0 ? null : (int) round($done / $expected * 100),
            'incomplete' => $late,
        ];
    }

    /**
     * 9.3.2 c 3 (and 7.5): the documents' reviews and read acknowledgements.
     *
     * @return array<string, mixed>
     */
    private function documentInputs(ManagementReview $review): array
    {
        $centre  = $review->getEducationalCentre();
        $overdue = [];
        $soon    = 0;
        foreach ($this->documents->reviewsDue($centre) as $row) {
            if ($row->reviewState === DocumentReviewSchedule::OVERDUE) {
                $overdue[] = $this->document($row);
            } elseif ($row->reviewState === DocumentReviewSchedule::SOON) {
                ++$soon;
            }
        }

        $documents = 0;
        $complete  = 0;
        $read      = 0;
        $readers   = 0;
        foreach ($this->readAcknowledgements->statusOf($this->documentRepository->findRequiringReadAcknowledgementByCentre($centre)) as $status) {
            ++$documents;
            $complete += $status->isComplete() ? 1 : 0;
            $read     += $status->readCount();
            $readers  += $status->total();
        }

        return [
            'reviewsOverdue' => $overdue,
            'reviewsSoon'    => $soon,
            'readDocuments'  => $documents,
            'readComplete'   => $complete,
            'readPercentage' => $readers === 0 ? null : (int) round($read / $readers * 100),
        ];
    }

    /** @return array<string, mixed> */
    private function action(ImprovementAction $action, \DateTimeImmutable $today): array
    {
        $teacher = $action->getResponsibleTeacher();

        return [
            'id'          => $action->getId()->toRfc4122(),
            'code'        => $action->getCode(),
            'description' => $action->getDescription(),
            'responsible' => $teacher !== null
                ? $teacher->getName()->getFirstName() . ' ' . $teacher->getName()->getLastName()
                : $action->getResponsibleProfile()?->getName(),
            'dueDate'     => $action->getDueDate()?->format('Y-m-d'),
            'status'      => $action->getStatus()->value,
            'overdue'     => $action->isOverdue($today),
            'result'      => $action->getStatus() === ImprovementActionStatus::Done ? $action->getResult() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function finding(Finding $finding): array
    {
        return [
            'id'         => $finding->getId()->toRfc4122(),
            'code'       => $finding->getCode(),
            'title'      => $finding->getTitle(),
            'kind'       => $finding->getKind()?->value,
            'status'     => $finding->getStatus()->value,
            'reportedAt' => $finding->getReportedAt()->format('Y-m-d'),
        ];
    }

    /** @return array<string, mixed> */
    private function audit(Audit $audit): array
    {
        $kinds = ['nonconformity' => 0, 'observation' => 0, 'improvement_opportunity' => 0];
        foreach ($this->findings->findByAudit($audit) as $finding) {
            if ($finding->getKind() !== null) {
                ++$kinds[$finding->getKind()->value];
            }
        }

        return [
            'id'           => $audit->getId()->toRfc4122(),
            'code'         => $audit->getCode(),
            'title'        => $audit->getTitle(),
            'plannedMonth' => $audit->getPlannedMonth()->format('Y-m-d'),
            'status'       => $audit->getStatus()->value,
            'findings'     => $kinds,
            'conclusion'   => $audit->getConclusion(),
        ];
    }

    /** @return array<string, mixed> */
    private function activity(ActivityStatusReportRow $row): array
    {
        return [
            'category'   => $row->categoryPath,
            'title'      => $row->title,
            'deadline'   => $row->deadline->format('Y-m-d'),
            'done'       => $row->done,
            'expected'   => $row->expected,
            'percentage' => $row->donePercentage(),
        ];
    }

    /** @return array<string, mixed> */
    private function document(DocumentMasterListRow $row): array
    {
        return [
            'section'      => $row->sectionPath,
            'folder'       => $row->folderName,
            'name'         => $row->documentName,
            'nextReviewAt' => $row->nextReviewAt?->format('Y-m-d'),
        ];
    }
}
