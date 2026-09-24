<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Audit;
use App\Entity\AuditChecklistTemplate;
use App\Entity\AuditItem;
use App\Entity\AuditProgram;
use App\Entity\AuditResult;
use App\Entity\AuditStatus;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingSeverity;
use App\Entity\Folder;
use App\Entity\QualityAttachment;
use App\Entity\Teacher;
use App\Repository\AuditProgramRepository;
use App\Repository\AuditRepository;
use App\Repository\FindingRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Internal audits: the yearly programme (its audits, copying last year's, approving it), who is
 * audited and whether an auditor would audit their own process, and each audit from preparation
 * to closing — the steps are the "audit" state machine's (guards in AuditWorkflowSubscriber).
 * Each method flushes; permission checks are the callers' (QualityVoter).
 */
final class AuditService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly WorkflowInterface $auditStateMachine,
        private readonly AuditProgramRepository $programs,
        private readonly AuditRepository $audits,
        private readonly FindingRepository $findings,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly FindingService $findingService,
        private readonly DocumentCreationService $files,
        private readonly QualityNotifier $notifier,
        private readonly ActivityLogger $activityLogger,
    ) {}

    // ── The programme ────────────────────────────────────────────────────────

    public function program(EducationalCentre $centre, AcademicYear $year): AuditProgram
    {
        $program = $this->programs->findByYear($year);
        if ($program === null) {
            $program = new AuditProgram($centre, $year);
            $this->em->persist($program);
        }

        return $program;
    }

    /**
     * Creates ($audit null) or edits an audit of $year's programme. A new one withdraws the
     * programme's approval: it has to be approved again.
     *
     * @param array{title: string, objective: ?string, scope: list<DocumentSection>, plannedMonth: \DateTimeImmutable, lead: ?Teacher, auditors: list<Teacher>} $data
     */
    public function saveAudit(EducationalCentre $centre, AcademicYear $year, ?Audit $audit, array $data): Audit
    {
        $isNew = $audit === null;
        if ($audit === null) {
            $program = $this->program($centre, $year);
            $audit   = new Audit($program, $this->nextCode($centre, $year), trim($data['title']), $data['plannedMonth'], $this->clock->now());
            $this->em->persist($audit);
            $program->withdrawApproval();
        }
        $audit->setTitle(trim($data['title']))
            ->setObjective(self::nullIfBlank($data['objective']))
            ->setScope($data['scope'])
            ->setPlannedMonth($data['plannedMonth'])
            ->setLeadAuditor($data['lead'])
            ->setAuditors($data['auditors']);
        $this->em->flush();

        $this->activityLogger->record($isNew ? 'audit.create' : 'audit.update', ['audit' => $audit->getCode() . ' ' . $audit->getTitle()], $centre);

        return $audit;
    }

    /** Only while still to be carried out; the programme has to be approved again. */
    public function deleteAudit(Audit $audit): void
    {
        if (!\in_array($audit->getStatus(), [AuditStatus::Planned, AuditStatus::Preparation], true)) {
            throw new \LogicException('Only an audit not started yet can be deleted.');
        }
        $label   = $audit->getCode() . ' ' . $audit->getTitle();
        $program = $audit->getProgram();
        $program->getAudits()->removeElement($audit);
        $program->withdrawApproval();
        $this->em->remove($audit);
        $this->em->flush();

        $this->activityLogger->record('audit.delete', ['audit' => $label], $program->getEducationalCentre());
    }

    public function approve(AuditProgram $program, Teacher $actor): void
    {
        $program->approve($actor, $this->clock->now());
        $this->em->flush();

        $this->activityLogger->record('audit_program.approve', ['year' => $program->getAcademicYear()->getName()], $program->getEducationalCentre());
    }

    /**
     * Starts $to's programme from $from's: the same audits (title, objective, scope, team), a year
     * later. Only into a programme with no audits yet.
     *
     * @return int how many audits were copied
     */
    public function copyProgram(EducationalCentre $centre, AcademicYear $from, AcademicYear $to): int
    {
        $source = $this->programs->findByYear($from);
        $target = $this->program($centre, $to);
        if ($source === null || !$target->getAudits()->isEmpty()) {
            return 0;
        }
        $now   = $this->clock->now();
        $shift = MeasurementCalendarTemplates::firstYear($to, $now) - MeasurementCalendarTemplates::firstYear($from, $now);

        $copied = 0;
        foreach ($source->getAudits() as $audit) {
            $copy = (new Audit($target, $this->nextCode($centre, $to, $copied), $audit->getTitle(), $audit->getPlannedMonth()->modify(($shift >= 0 ? '+' : '') . $shift . ' years'), $now))
                ->setObjective($audit->getObjective())
                ->setScope($audit->getScope())
                ->setLeadAuditor($audit->getLeadAuditor())
                ->setAuditors($audit->getAuditors());
            $this->em->persist($copy);
            ++$copied;
        }
        $this->em->flush();

        $this->activityLogger->record('audit_program.copy', ['from' => $from->getName(), 'to' => $to->getName(), 'count' => $copied], $centre);

        return $copied;
    }

    /** AI-2026-01: the programme's first year and the next number (plus $offset, while copying unflushed ones). */
    private function nextCode(EducationalCentre $centre, AcademicYear $year, int $offset = 0): string
    {
        $prefix = 'AI-' . MeasurementCalendarTemplates::firstYear($year, $this->clock->now()) . '-';
        $max    = 0;
        foreach ($this->audits->findCodesStartingWith($centre, $prefix) as $code) {
            $number = substr($code, \strlen($prefix));
            if (ctype_digit($number)) {
                $max = max($max, (int) $number);
            }
        }

        return $prefix . str_pad((string) ($max + 1 + $offset), 2, '0', \STR_PAD_LEFT);
    }

    // ── Who is audited, and independence ─────────────────────────────────────

    /**
     * Who is audited: whoever holds a responsible profile on a folder of the scope (its sections
     * and the ones under them), by name.
     *
     * @return list<Teacher>
     */
    public function auditees(Audit $audit): array
    {
        return array_values(array_map(static fn (array $entry): Teacher => $entry['teacher'], $this->responsibles($audit->getScope())));
    }

    /**
     * The team members who'd audit their own process (ISO 9001 9.2.2 c: auditors are objective
     * and impartial), with the sections they're responsible for.
     *
     * @param iterable<DocumentSection> $scope
     * @param list<Teacher>             $team
     *
     * @return list<array{teacher: Teacher, sections: list<DocumentSection>}>
     */
    public function independenceConflicts(iterable $scope, array $team): array
    {
        $responsibles = $this->responsibles($scope);
        $conflicts    = [];
        foreach ($team as $member) {
            $entry = $responsibles[$member->getId()->toRfc4122()] ?? null;
            if ($entry !== null) {
                $conflicts[] = ['teacher' => $member, 'sections' => $entry['sections']];
            }
        }

        return $conflicts;
    }

    /**
     * Whoever holds a responsible profile on a folder in $scope, with the scope's sections they
     * answer for, by teacher id.
     *
     * @param iterable<DocumentSection> $scope
     *
     * @return array<string, array{teacher: Teacher, sections: list<DocumentSection>}>
     */
    private function responsibles(iterable $scope): array
    {
        $found = [];
        foreach ($scope as $section) {
            $pairs = [];
            foreach (self::foldersUnder($section) as $folder) {
                foreach ($folder->getResponsibleProfiles() as $responsible) {
                    $pairs[] = [$responsible->getSpecificProfile(), $responsible->getListItem()];
                }
            }
            foreach ($this->assignments->findTeachersHoldingProfileAndListItemForPairs($pairs) as $teachers) {
                foreach ($teachers as $teacher) {
                    $id = $teacher->getId()->toRfc4122();
                    $found[$id] ??= ['teacher' => $teacher, 'sections' => []];
                    if (!\in_array($section, $found[$id]['sections'], true)) {
                        $found[$id]['sections'][] = $section;
                    }
                }
            }
        }
        uasort($found, static fn (array $a, array $b): int => strcasecmp($a['teacher']->getName()->getLastName(), $b['teacher']->getName()->getLastName()));

        return $found;
    }

    /** @return list<Folder> the folders of $section and of every section under it */
    public static function foldersUnder(DocumentSection $section): array
    {
        $folders = array_values($section->getFolders()->toArray());
        foreach ($section->getChildren() as $child) {
            $folders = [...$folders, ...self::foldersUnder($child)];
        }

        return $folders;
    }

    // ── Preparing and carrying it out ────────────────────────────────────────

    /**
     * Saves the preparation — the day and time, the objective, and the checklist rows (a row with
     * the id of one of its points keeps it and what was recorded on it; one left out is removed) —
     * and moves a planned audit on to "preparation".
     *
     * @param list<array{id: ?string, clause: ?string, question: string, guidance: ?string}> $rows
     */
    public function savePreparation(Audit $audit, Teacher $actor, ?\DateTimeImmutable $scheduledAt, ?string $objective, array $rows): void
    {
        $audit->setScheduledAt($scheduledAt)->setObjective(self::nullIfBlank($objective));

        $existing = [];
        foreach ($audit->getItems() as $item) {
            $existing[$item->getId()->toRfc4122()] = $item;
        }
        $kept = [];
        foreach ($rows as $position => $row) {
            $item = $row['id'] !== null ? ($existing[$row['id']] ?? null) : null;
            if ($item !== null) {
                $item->update($position, $row['clause'], $row['question'], $row['guidance']);
                $kept[$row['id']] = true;
            } else {
                $audit->addItem($row['clause'], $row['question'], $row['guidance'])->update($position, $row['clause'], $row['question'], $row['guidance']);
            }
        }
        foreach ($existing as $id => $item) {
            if (!isset($kept[$id])) {
                $audit->removeItem($item);
            }
        }

        $this->startPreparing($audit, $actor);
        $this->em->flush();
    }

    /** Appends a library checklist's points to the audit's. */
    public function addChecklist(Audit $audit, Teacher $actor, AuditChecklistTemplate $template): void
    {
        foreach ($template->getItems() as $item) {
            $audit->addItem($item['clause'], $item['question'], $item['guidance']);
        }
        $this->startPreparing($audit, $actor);
        $this->em->flush();
    }

    private function startPreparing(Audit $audit, Teacher $actor): void
    {
        if ($audit->getStatus() === AuditStatus::Planned && $this->auditStateMachine->can($audit, 'prepare')) {
            $this->auditStateMachine->apply($audit, 'prepare', ['actor' => $actor]);
        }
    }

    public function start(Audit $audit, Teacher $actor): void
    {
        $this->auditStateMachine->apply($audit, 'start', ['actor' => $actor]);
        $this->em->flush();
    }

    /** What was found on one point; saved as it's filled in, so the audit can be left and resumed. */
    public function record(AuditItem $item, ?AuditResult $result, ?FindingSeverity $severity, ?string $evidence): void
    {
        $item->record($result, $severity, self::nullIfBlank($evidence));
        $this->em->flush();
    }

    public function attach(AuditItem $item, Teacher $actor, UploadedFile $file): QualityAttachment
    {
        $content    = (string) file_get_contents($file->getPathname());
        $stored     = $this->files->storeFile($content, $file->getMimeType() ?? 'application/octet-stream', $file->getClientOriginalName());
        $attachment = QualityAttachment::forAuditItem($item, $stored, mb_substr($file->getClientOriginalName(), 0, 255), $actor, $this->clock->now());
        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    public function saveReport(Audit $audit, ?string $strengths, ?string $conclusion): void
    {
        $audit->setReport(self::nullIfBlank($strengths), self::nullIfBlank($conclusion));
        $this->em->flush();
    }

    /**
     * Issues the report: each nonconformity, observation and improvement becomes a finding (see
     * FindingService::fromAuditItem()); the people audited, the team and the quality managers are
     * told. With nothing to deal with, it's closed there and then.
     *
     * @return list<Finding> the findings it raised
     */
    public function issueReport(Audit $audit, Teacher $actor): array
    {
        $this->auditStateMachine->apply($audit, 'issue_report', ['actor' => $actor]);
        $audit->markReportIssued($actor, $this->clock->now());
        $this->em->flush();

        $raised = [];
        foreach ($audit->getItems() as $item) {
            $finding = $this->findingService->fromAuditItem($item, $audit->getLeadAuditor() ?? $actor);
            if ($finding !== null) {
                $raised[] = $finding;
            }
        }

        $this->notifier->auditReportIssued($audit, [...$this->auditees($audit), ...$audit->getTeam(), ...$this->notifier->qualityManagers($audit->getEducationalCentre())], \count($raised));
        $this->closeIfDone($audit);

        return $raised;
    }

    /** @return list<Finding> the findings the audit's report raised */
    public function findingsOf(Audit $audit): array
    {
        return $this->findings->findByAudit($audit);
    }

    /** Closes an audit whose report is issued once every finding it raised is closed (or discarded). */
    public function closeIfDone(Audit $audit): void
    {
        if ($audit->getStatus() === AuditStatus::ReportIssued && $this->auditStateMachine->can($audit, 'close')) {
            $this->auditStateMachine->apply($audit, 'close');
            $audit->markClosed($this->clock->now());
            $this->em->flush();
        }
    }

    /** @return list<string> the steps $audit can take now, for the current user */
    public function nextSteps(Audit $audit): array
    {
        return array_values(array_map(static fn ($t): string => $t->getName(), $this->auditStateMachine->getEnabledTransitions($audit)));
    }

    /** @return list<string> why $transition can't be taken now */
    public function blockers(Audit $audit, string $transition): array
    {
        $messages = [];
        foreach ($this->auditStateMachine->buildTransitionBlockerList($audit, $transition) as $blocker) {
            $messages[] = $blocker->getMessage();
        }

        return $messages;
    }

    private static function nullIfBlank(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
