<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingEventKind;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingSeverity;
use App\Entity\FindingStatus;
use App\Entity\FindingTimelineEntry;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionType;
use App\Entity\Measurement;
use App\Entity\QualityAttachment;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Everything that happens to a finding and its actions, in one place: each method changes the
 * data, takes the workflow step it implies (whose guards and effects live in
 * FindingWorkflowSubscriber), records what isn't a workflow step in the timeline, and flushes.
 * Permission checks are the callers' (QualityVoter), as with the rest of the app's services;
 * the workflow's own guards stop a step the user may not take regardless.
 */
final class FindingService
{
    /** Longest title taken from the first line of a report. */
    private const int TITLE_LENGTH = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly WorkflowInterface $findingStateMachine,
        private readonly FindingCodeGenerator $codes,
        private readonly DocumentCreationService $files,
        private readonly QualityNotifier $notifier,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * "Comunicar incidencia": what happened, where (if known) and any files.
     *
     * @param list<UploadedFile> $files
     */
    public function report(EducationalCentre $centre, Teacher $reporter, string $description, ?DocumentSection $section, array $files = []): Finding
    {
        $description = trim($description);
        $finding     = (new Finding($centre, self::titleFrom($description), $description, $reporter, $this->clock->now()))->setSection($section);
        $this->em->persist($finding);
        $this->em->persist(new FindingTimelineEntry($finding, FindingEventKind::Reported, $reporter, $this->clock->now()));
        foreach ($files as $file) {
            $this->attach($finding, null, $reporter, $file, timeline: false);
        }
        $this->em->flush();

        $this->activityLogger->record('finding.report', ['finding' => $finding->getTitle()], $centre);
        $this->notifier->reported($finding);

        return $finding;
    }

    /** Classifies a reported finding; a nonconformity goes on to cause analysis, the rest straight to execution. */
    public function classify(
        Finding $finding,
        Teacher $actor,
        FindingKind $kind,
        ?FindingSeverity $severity,
        string $title,
        ?DocumentSection $section,
        FindingOrigin $origin,
        ?Teacher $analysisResponsible,
        ?\DateTimeImmutable $analysisDueDate,
    ): void {
        $now = $this->clock->now();
        $finding->setKind($kind)
            ->setSeverity($kind === FindingKind::Nonconformity ? $severity : null)
            ->setTitle(trim($title) !== '' ? trim($title) : $finding->getTitle())
            ->setSection($section)
            ->setOrigin($origin)
            ->setAnalysisResponsible($kind === FindingKind::Nonconformity ? $analysisResponsible : null)
            ->setAnalysisDueDate($kind === FindingKind::Nonconformity ? $analysisDueDate : null)
            ->setCode($this->codes->next($finding, $kind, $now))
            ->markClassified($actor, $now);

        $this->findingStateMachine->apply(
            $finding,
            $kind === FindingKind::Nonconformity ? 'classify_nonconformity' : 'classify_other',
            ['actor' => $actor],
        );
        $this->em->flush();
    }

    public function discard(Finding $finding, Teacher $actor, string $reason): void
    {
        $finding->setDiscardReason(trim($reason));
        $this->findingStateMachine->apply($finding, 'discard', ['actor' => $actor, 'text' => $reason]);
        $this->em->flush();
    }

    /**
     * Saves the cause analysis (the 5 whys and the root cause) without moving on — it can be
     * worked on in several goes before submitAnalysis().
     *
     * @param list<string> $whys
     */
    public function saveAnalysis(Finding $finding, array $whys, string $rootCause): void
    {
        $finding->setWhys($whys)->setRootCause(trim($rootCause) !== '' ? trim($rootCause) : null);
        $this->em->flush();
    }

    /** Analysis finished and corrective actions defined: on to executing them. */
    public function submitAnalysis(Finding $finding, Teacher $actor): void
    {
        $this->findingStateMachine->apply($finding, 'submit_analysis', ['actor' => $actor]);
        $this->em->flush();
        $this->requestVerificationIfDone($finding, $actor);
    }

    /**
     * Adds an action — to a finding, or without one (improvement plan). A repair action is often
     * recorded already done ($alreadyDone, with $result): what was done on the spot.
     */
    public function addAction(
        EducationalCentre $centre,
        ?Finding $finding,
        Teacher $actor,
        ImprovementActionType $type,
        string $description,
        ?Teacher $responsibleTeacher,
        ?SpecificProfile $responsibleProfile,
        ?\DateTimeImmutable $dueDate,
        bool $alreadyDone = false,
        ?string $result = null,
    ): ImprovementAction {
        $now    = $this->clock->now();
        $action = (new ImprovementAction($centre, $finding, $type, trim($description), $actor, $now))
            ->assignTo($responsibleTeacher ?? ($responsibleProfile === null ? $actor : null), $responsibleProfile)
            ->setDueDate($dueDate);
        if ($alreadyDone) {
            $action->complete($actor, $now, $result !== null && trim($result) !== '' ? trim($result) : null);
        }
        $this->em->persist($action);

        if ($finding !== null) {
            $this->em->persist(new FindingTimelineEntry($finding, $alreadyDone ? FindingEventKind::ActionDone : FindingEventKind::ActionAdded, $actor, $now, $action->getResult(), [
                'action' => $action->getDescription(),
                'type'   => $type->value,
            ]));
        }
        $this->em->flush();

        if (!$alreadyDone) {
            $this->notifier->actionAssigned($action);
        }

        return $action;
    }

    /**
     * A preventive or improvement action of $year's improvement plan: no finding, its own code
     * (PM-2026-003), what it's meant to achieve and the process it concerns.
     */
    public function createPlanAction(
        EducationalCentre $centre,
        AcademicYear $year,
        Teacher $actor,
        ImprovementActionType $type,
        string $description,
        ?string $goal,
        ?DocumentSection $section,
        ?Teacher $responsibleTeacher,
        ?SpecificProfile $responsibleProfile,
        ?\DateTimeImmutable $dueDate,
        ?Measurement $measurement = null,
    ): ImprovementAction {
        $now    = $this->clock->now();
        $action = (new ImprovementAction($centre, null, $type, trim($description), $actor, $now))
            ->setMeasurement($measurement)
            ->setCode($this->codes->nextPlanAction($centre, $now))
            ->setAcademicYear($year)
            ->setGoal(self::nullIfBlank($goal))
            ->setSection($section)
            ->assignTo($responsibleTeacher ?? ($responsibleProfile === null ? $actor : null), $responsibleProfile)
            ->setDueDate($dueDate);
        $this->em->persist($action);
        // Proposed from an off-target indicator value: that value is dealt with.
        $measurement?->markReviewed($actor, $now);
        $this->em->flush();

        $this->activityLogger->record('improvement_action.create', ['action' => $action->getCode() . ' ' . $action->getDescription()], $centre);
        $this->notifier->actionAssigned($action);

        return $action;
    }

    /** Edits a plan action; whoever it's newly assigned to is told. */
    public function updatePlanAction(
        ImprovementAction $action,
        ImprovementActionType $type,
        string $description,
        ?string $goal,
        ?DocumentSection $section,
        ?Teacher $responsibleTeacher,
        ?SpecificProfile $responsibleProfile,
        ?\DateTimeImmutable $dueDate,
    ): void {
        $before = [$action->getResponsibleTeacher()?->getId()->toRfc4122(), $action->getResponsibleProfile()?->getId()->toRfc4122()];
        $action->setType($type)
            ->setDescription(trim($description))
            ->setGoal(self::nullIfBlank($goal))
            ->setSection($section)
            ->assignTo($responsibleTeacher, $responsibleTeacher === null ? $responsibleProfile : null)
            ->setDueDate($dueDate);
        $this->em->flush();

        $this->activityLogger->record('improvement_action.update', ['action' => $action->getCode() . ' ' . $action->getDescription()], $action->getEducationalCentre());
        $after = [$action->getResponsibleTeacher()?->getId()->toRfc4122(), $action->getResponsibleProfile()?->getId()->toRfc4122()];
        if ($after !== $before && !$action->isDone()) {
            $this->notifier->actionAssigned($action);
        }
    }

    /** Deletes a plan action, with its evidence. */
    public function deletePlanAction(ImprovementAction $action): void
    {
        $label  = $action->getCode() . ' ' . $action->getDescription();
        $centre = $action->getEducationalCentre();
        foreach ($action->getAttachments() as $attachment) {
            $this->em->remove($attachment);
        }
        $this->em->remove($action);
        $this->em->flush();

        $this->activityLogger->record('improvement_action.delete', ['action' => $label], $centre);
    }

    public function startAction(ImprovementAction $action, Teacher $actor): void
    {
        $action->start();
        if ($action->getFinding() !== null) {
            $this->em->persist(new FindingTimelineEntry($action->getFinding(), FindingEventKind::ActionStarted, $actor, $this->clock->now(), null, ['action' => $action->getDescription()]));
        }
        $this->em->flush();
    }

    /**
     * Marks the action done. When it was the last one of a nonconformity being executed, the
     * effectiveness check is requested on its own.
     */
    public function completeAction(ImprovementAction $action, Teacher $actor, ?string $result): void
    {
        $now = $this->clock->now();
        $action->complete($actor, $now, $result !== null && trim($result) !== '' ? trim($result) : null);
        $finding = $action->getFinding();
        if ($finding !== null) {
            $this->em->persist(new FindingTimelineEntry($finding, FindingEventKind::ActionDone, $actor, $now, $action->getResult(), ['action' => $action->getDescription()]));
        }
        $this->em->flush();

        $this->activityLogger->record('improvement_action.complete', ['action' => $action->getDescription()], $action->getEducationalCentre());

        if ($finding !== null) {
            $this->requestVerificationIfDone($finding, $actor);
        }
    }

    public function verify(Finding $finding, Teacher $actor, bool $effective, string $notes): void
    {
        $finding->recordVerification($effective, trim($notes), $actor, $this->clock->now());
        $this->findingStateMachine->apply($finding, $effective ? 'verify_effective' : 'verify_ineffective', ['actor' => $actor, 'text' => $notes]);
        $this->em->flush();
    }

    /** Closes an observation or an improvement opportunity once its actions (if any) are done. */
    public function close(Finding $finding, Teacher $actor, string $notes): void
    {
        $this->findingStateMachine->apply($finding, 'close', ['actor' => $actor, 'text' => $notes]);
        $this->em->flush();
    }

    public function comment(Finding $finding, Teacher $actor, string $text): void
    {
        $this->em->persist(new FindingTimelineEntry($finding, FindingEventKind::Comment, $actor, $this->clock->now(), trim($text)));
        $this->em->flush();
    }

    /**
     * Attaches a file to $action (its evidence) when given, or else to $finding. A plan action has
     * no finding, and so no timeline to record it in.
     */
    public function attach(?Finding $finding, ?ImprovementAction $action, Teacher $actor, UploadedFile $file, bool $timeline = true): QualityAttachment
    {
        if ($action === null && $finding === null) {
            throw new \InvalidArgumentException('A file is attached to a finding or to an action.');
        }
        $content  = (string) file_get_contents($file->getPathname());
        $stored   = $this->files->storeFile($content, $file->getMimeType() ?? 'application/octet-stream', $file->getClientOriginalName());
        $now      = $this->clock->now();
        $filename = mb_substr($file->getClientOriginalName(), 0, 255);

        $attachment = $action !== null
            ? QualityAttachment::forAction($action, $stored, $filename, $actor, $now)
            : QualityAttachment::forFinding($finding, $stored, $filename, $actor, $now);
        $this->em->persist($attachment);

        if ($finding === null) {
            $this->em->flush();
        } elseif ($timeline) {
            $this->em->persist(new FindingTimelineEntry($finding, FindingEventKind::AttachmentAdded, $actor, $now, null, array_filter([
                'file'   => $filename,
                'action' => $action?->getDescription(),
            ])));
            $this->em->flush();
        }

        return $attachment;
    }

    /**
     * The steps $finding can take now, for the current user (the workflow's enabled transitions).
     *
     * @return list<string>
     */
    public function nextSteps(Finding $finding): array
    {
        return array_values(array_map(static fn ($t): string => $t->getName(), $this->findingStateMachine->getEnabledTransitions($finding)));
    }

    /**
     * Why $transition can't be taken now, one message per reason (empty when it can).
     *
     * @return list<string>
     */
    public function blockers(Finding $finding, string $transition): array
    {
        $messages = [];
        foreach ($this->findingStateMachine->buildTransitionBlockerList($finding, $transition) as $blocker) {
            $messages[] = $blocker->getMessage();
        }

        return $messages;
    }

    private function requestVerificationIfDone(Finding $finding, Teacher $actor): void
    {
        if ($finding->getStatus() === FindingStatus::Execution && $this->findingStateMachine->can($finding, 'request_verification')) {
            $this->findingStateMachine->apply($finding, 'request_verification', ['actor' => $actor]);
            $this->em->flush();
        }
    }

    private static function nullIfBlank(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }

    /** The first line of the report, shortened on a word boundary. */
    public static function titleFrom(string $description): string
    {
        $firstLine = trim(strtok($description, "\n") ?: $description);
        if (mb_strlen($firstLine) <= self::TITLE_LENGTH) {
            return $firstLine;
        }

        $cut       = mb_substr($firstLine, 0, self::TITLE_LENGTH);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false && $lastSpace > 60 ? mb_substr($cut, 0, $lastSpace) : $cut, " ,.;:") . '…';
    }
}
