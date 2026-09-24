<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Finding;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingSeverity;
use App\Entity\FindingStatus;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionType;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\DocumentSectionRepository;
use App\Repository\ImprovementActionRepository;
use App\Repository\SpecificProfileRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\QualityVoter;
use App\Service\FindingService;
use App\Service\SectionChoiceBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A finding's page. Up top, "Lo siguiente": the one step it's waiting for, with its form for
 * whoever may take it (classify, analyse, carry out the actions, verify, close) — or who it's
 * waiting on, for everyone else. Below, the report, the analysis, the actions and the timeline.
 * Every change goes through FindingService; permissions through QualityVoter, and the "finding"
 * workflow's guards say why a step isn't possible yet.
 */
#[AsLiveComponent]
class FindingDetailComponent extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    private const int WHYS = 5;

    #[LiveProp]
    public Finding $finding;

    // ── Classification ────────────────────────────────────────────────────────
    #[LiveProp(writable: true)]
    public string $kind = '';

    #[LiveProp(writable: true)]
    public string $severity = 'minor';

    #[LiveProp(writable: true)]
    public string $title = '';

    #[LiveProp(writable: true)]
    public string $sectionId = '';

    #[LiveProp(writable: true)]
    public string $origin = 'internal_report';

    #[LiveProp(writable: true)]
    public string $responsibleId = '';

    #[LiveProp(writable: true)]
    public string $analysisDueDate = '';

    #[LiveProp(writable: true)]
    public bool $discarding = false;

    #[LiveProp(writable: true)]
    public string $discardReason = '';

    // ── Analysis ──────────────────────────────────────────────────────────────
    /** @var list<string> */
    #[LiveProp(writable: true)]
    public array $whys = [];

    #[LiveProp(writable: true)]
    public string $rootCause = '';

    // ── New action ────────────────────────────────────────────────────────────
    #[LiveProp(writable: true)]
    public bool $addingAction = false;

    #[LiveProp(writable: true)]
    public string $actionType = 'corrective';

    #[LiveProp(writable: true)]
    public string $actionDescription = '';

    /** "t:<teacher id>" or "p:<profile id>", or "" for whoever adds it. */
    #[LiveProp(writable: true)]
    public string $actionResponsible = '';

    #[LiveProp(writable: true)]
    public string $actionDueDate = '';

    #[LiveProp(writable: true)]
    public bool $actionAlreadyDone = false;

    #[LiveProp(writable: true)]
    public string $actionResult = '';

    // ── Completing an action, verifying, closing, commenting ─────────────────
    #[LiveProp(writable: true)]
    public string $completingActionId = '';

    #[LiveProp(writable: true)]
    public string $completeResult = '';

    #[LiveProp(writable: true)]
    public string $verifyNotes = '';

    #[LiveProp(writable: true)]
    public string $closeNotes = '';

    #[LiveProp(writable: true)]
    public string $comment = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    public function __construct(
        private readonly FindingService $findings,
        private readonly TeacherRepository $teachers,
        private readonly SpecificProfileRepository $profiles,
        private readonly DocumentSectionRepository $sections,
        private readonly ImprovementActionRepository $actions,
        private readonly SectionChoiceBuilder $sectionChoices,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    public function mount(Finding $finding): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::FINDING_VIEW, $finding);

        $this->finding         = $finding;
        $this->kind            = $finding->getKind()->value ?? '';
        $this->severity        = $finding->getSeverity()->value ?? 'minor';
        $this->title           = $finding->getTitle();
        $this->sectionId       = $finding->getSection()?->getId()->toRfc4122() ?? '';
        $this->origin          = $finding->getOrigin()->value;
        $this->responsibleId   = $finding->getAnalysisResponsible()?->getId()->toRfc4122() ?? '';
        $this->analysisDueDate = ($finding->getAnalysisDueDate() ?? $this->clock->now()->modify('+15 days'))->format('Y-m-d');
        $this->whys            = array_pad($finding->getWhys(), self::WHYS, '');
        $this->rootCause       = $finding->getRootCause() ?? '';
    }

    // ── What the template asks ────────────────────────────────────────────────

    public function canManage(): bool
    {
        return $this->isGranted(QualityVoter::MANAGE, $this->finding->getEducationalCentre());
    }

    public function canAnalyze(): bool
    {
        return $this->isGranted(QualityVoter::FINDING_ANALYZE, $this->finding);
    }

    public function canWorkOn(ImprovementAction $action): bool
    {
        return $this->isGranted(QualityVoter::ACTION_WORK, $action);
    }

    /** Actions can be added while analysing (whoever analyses) and while executing (the managers). */
    public function canAddActions(): bool
    {
        return match ($this->finding->getStatus()) {
            FindingStatus::Analysis  => $this->canAnalyze(),
            FindingStatus::Execution => $this->canManage(),
            default                  => false,
        };
    }

    /** @return list<string> the workflow steps the current user can take now */
    public function getNextSteps(): array
    {
        return $this->findings->nextSteps($this->finding);
    }

    /** @return list<string> why $transition can't be taken yet */
    public function blockers(string $transition): array
    {
        return $this->findings->blockers($this->finding, $transition);
    }

    /** @return list<Teacher> teachers of the centre's active year, by name */
    public function getTeacherChoices(): array
    {
        $year = $this->finding->getEducationalCentre()->getActiveAcademicYear();

        return $year === null ? [] : array_values(array_filter($this->teachers->findByAcademicYearOrderedByName($year), static fn (Teacher $t): bool => $t->isActive()));
    }

    /** @return list<SpecificProfile> */
    public function getProfileChoices(): array
    {
        return array_values(array_filter($this->profiles->findByCentre($this->finding->getEducationalCentre()), static fn (SpecificProfile $p): bool => $p->isActive()));
    }

    /** @return list<array{id: string, label: string, indented: string, depth: int}> */
    public function getSectionChoices(): array
    {
        return $this->sectionChoices->choices($this->teacher(), $this->finding->getEducationalCentre());
    }

    /** @return list<FindingKind> */
    public function getKinds(): array
    {
        return FindingKind::cases();
    }

    /** @return list<FindingOrigin> */
    public function getOrigins(): array
    {
        return FindingOrigin::cases();
    }

    /** @return list<ImprovementActionType> the types a finding's actions can take */
    public function getActionTypes(): array
    {
        return $this->finding->isNonconformity()
            ? [ImprovementActionType::Corrective, ImprovementActionType::Repair]
            : [ImprovementActionType::Improvement, ImprovementActionType::Preventive, ImprovementActionType::Repair];
    }

    // ── Classification ────────────────────────────────────────────────────────

    #[LiveAction]
    public function classify(): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $this->finding->getEducationalCentre());
        $this->errors = [];

        $kind   = FindingKind::tryFrom($this->kind);
        $origin = FindingOrigin::tryFrom($this->origin) ?? FindingOrigin::InternalReport;
        if ($kind === null) {
            $this->errors['kind'] = $this->t('classify.error.kind');
        }
        if (trim($this->title) === '') {
            $this->errors['title'] = $this->t('classify.error.title');
        }
        $responsible = $kind === FindingKind::Nonconformity ? $this->teacherFromId($this->responsibleId) : null;
        $dueDate     = $kind === FindingKind::Nonconformity ? $this->date($this->analysisDueDate, 'analysisDueDate') : null;
        if ($kind === null || $this->errors !== []) {
            return;
        }

        $this->run(fn () => $this->findings->classify(
            $this->finding,
            $this->teacher(),
            $kind,
            FindingSeverity::tryFrom($this->severity) ?? FindingSeverity::Minor,
            $this->title,
            $this->sectionId === '' ? null : $this->sections->findByIdAndCentre($this->sectionId, $this->finding->getEducationalCentre()),
            $origin,
            $responsible,
            $dueDate,
        ), 'classify.flash.done');
    }

    #[LiveAction]
    public function toggleDiscard(): void
    {
        $this->discarding = !$this->discarding;
        $this->errors     = [];
    }

    #[LiveAction]
    public function discard(): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $this->finding->getEducationalCentre());
        if (trim($this->discardReason) === '') {
            $this->errors = ['discardReason' => $this->t('discard.error.reason')];

            return;
        }

        $this->run(fn () => $this->findings->discard($this->finding, $this->teacher(), $this->discardReason), 'discard.flash.done');
        $this->discarding = false;
    }

    // ── Analysis ──────────────────────────────────────────────────────────────

    #[LiveAction]
    public function saveAnalysis(): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::FINDING_ANALYZE, $this->finding);
        $this->findings->saveAnalysis($this->finding, $this->whys, $this->rootCause);
        $this->errors = [];
        $this->flash('analysis.flash.saved');
    }

    #[LiveAction]
    public function submitAnalysis(): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::FINDING_ANALYZE, $this->finding);
        $this->findings->saveAnalysis($this->finding, $this->whys, $this->rootCause);
        $this->run(fn () => $this->findings->submitAnalysis($this->finding, $this->teacher()), 'analysis.flash.submitted', 'submit_analysis');
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    #[LiveAction]
    public function toggleAddAction(): void
    {
        $this->addingAction      = !$this->addingAction;
        $this->actionType        = $this->getActionTypes()[0]->value;
        $this->actionDescription = '';
        $this->actionResponsible = '';
        $this->actionDueDate     = $this->clock->now()->modify('+30 days')->format('Y-m-d');
        $this->actionAlreadyDone = false;
        $this->actionResult      = '';
        $this->errors            = [];
    }

    #[LiveAction]
    public function addAction(): void
    {
        if (!$this->canAddActions()) {
            throw $this->createAccessDeniedException();
        }
        $this->errors = [];

        $type = ImprovementActionType::tryFrom($this->actionType);
        if ($type === null || !\in_array($type, $this->getActionTypes(), true)) {
            $this->errors['actionType'] = $this->t('action.error.type');
        }
        if (trim($this->actionDescription) === '') {
            $this->errors['actionDescription'] = $this->t('action.error.description');
        }
        [$teacher, $profile] = $this->responsibleFrom($this->actionResponsible);
        $dueDate             = $this->actionAlreadyDone ? null : $this->date($this->actionDueDate, 'actionDueDate');
        if ($type === null || $this->errors !== []) {
            return;
        }

        $this->findings->addAction(
            $this->finding->getEducationalCentre(),
            $this->finding,
            $this->teacher(),
            $type,
            $this->actionDescription,
            $teacher,
            $profile,
            $dueDate,
            $this->actionAlreadyDone,
            $this->actionResult,
        );
        $this->addingAction = false;
        $this->flash('action.flash.added');
    }

    #[LiveAction]
    public function startAction(#[LiveArg] string $id): void
    {
        $action = $this->requireAction($id);
        $this->findings->startAction($action, $this->teacher());
    }

    #[LiveAction]
    public function toggleCompleteAction(#[LiveArg] string $id): void
    {
        $this->completingActionId = $this->completingActionId === $id ? '' : $id;
        $this->completeResult     = '';
        $this->errors             = [];
    }

    #[LiveAction]
    public function completeAction(): void
    {
        $action = $this->requireAction($this->completingActionId);
        if (trim($this->completeResult) === '') {
            $this->errors = ['completeResult' => $this->t('action.error.result')];

            return;
        }

        $this->findings->completeAction($action, $this->teacher(), $this->completeResult);
        $this->completingActionId = '';
        $this->errors             = [];
        $this->flash('action.flash.done');
    }

    // ── Verification, closing, comments ───────────────────────────────────────

    /** @param string $outcome "effective" or "ineffective" (a string: action args arrive as request attributes) */
    #[LiveAction]
    public function verify(#[LiveArg] string $outcome): void
    {
        $effective = $outcome === 'effective';
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $this->finding->getEducationalCentre());
        if (trim($this->verifyNotes) === '') {
            $this->errors = ['verifyNotes' => $this->t('verify.error.notes')];

            return;
        }

        $this->run(
            fn () => $this->findings->verify($this->finding, $this->teacher(), $effective, $this->verifyNotes),
            $effective ? 'verify.flash.effective' : 'verify.flash.ineffective',
            $effective ? 'verify_effective' : 'verify_ineffective',
        );
    }

    #[LiveAction]
    public function close(): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $this->finding->getEducationalCentre());
        $this->run(fn () => $this->findings->close($this->finding, $this->teacher(), $this->closeNotes), 'close.flash.done', 'close');
    }

    #[LiveAction]
    public function addComment(): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::FINDING_VIEW, $this->finding);
        if (trim($this->comment) === '') {
            return;
        }

        $this->findings->comment($this->finding, $this->teacher(), $this->comment);
        $this->comment = '';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Runs a workflow step; when its guards stop it, their reasons become the errors. */
    private function run(callable $step, string $flashKey, ?string $transition = null): void
    {
        try {
            $step();
            $this->errors = [];
            $this->flash($flashKey);
        } catch (NotEnabledTransitionException $e) {
            $reasons      = $transition === null ? [] : $this->blockers($transition);
            $this->errors = ['step' => $reasons === [] ? $this->t('step.error.not_now') : implode(' ', $reasons)];
        }
    }

    private function requireAction(string $id): ImprovementAction
    {
        $action = $this->actions->findByIdAndCentre($id, $this->finding->getEducationalCentre());
        if ($action === null || $action->getFinding() !== $this->finding) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(QualityVoter::ACTION_WORK, $action);

        return $action;
    }

    private function teacherFromId(string $id): ?Teacher
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->getTeacherChoices() as $teacher) {
            if ($teacher->getId()->toRfc4122() === $id) {
                return $teacher;
            }
        }
        $this->errors['responsible'] = $this->t('classify.error.responsible');

        return null;
    }

    /** @return array{0: ?Teacher, 1: ?SpecificProfile} */
    private function responsibleFrom(string $value): array
    {
        if (str_starts_with($value, 't:')) {
            return [$this->teacherFromId(substr($value, 2)), null];
        }
        if (str_starts_with($value, 'p:')) {
            $profile = $this->profiles->findByIdAndCentre(substr($value, 2), $this->finding->getEducationalCentre());
            if ($profile === null) {
                $this->errors['actionResponsible'] = $this->t('classify.error.responsible');
            }

            return [null, $profile];
        }

        return [null, null];
    }

    private function date(string $value, string $field): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            $this->errors[$field] = $this->t('error.date');

            return null;
        }

        return $date;
    }

    private function flash(string $key): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $this->t($key)]);
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'quality');
    }
}
