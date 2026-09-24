<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionType;
use App\Entity\Measurement;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\DocumentSectionRepository;
use App\Repository\ImprovementActionRepository;
use App\Repository\MeasurementRepository;
use App\Security\Voter\QualityVoter;
use App\Service\FindingService;
use App\Service\ResponsibleChoices;
use App\Service\SectionChoiceBuilder;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The improvement plan: the preventive and improvement actions each academic year sets itself,
 * with no finding behind them (a finding's actions live on the finding's page). The list is for
 * whoever sees everything in "Mejora continua"; adding, editing and deleting, for whoever manages
 * it; carrying an action out, for its responsible too (QualityVoter). New actions always go into
 * the active year's plan; the list shows the year being viewed.
 */
#[Route('/mejora')]
class ImprovementPlanController extends AbstractController
{
    /** The action types the plan takes: the other two answer to a finding. */
    public const array PLAN_TYPES = [ImprovementActionType::Improvement, ImprovementActionType::Preventive];

    public function __construct(
        private readonly ImprovementActionRepository $actions,
        private readonly DocumentSectionRepository $sections,
        private readonly MeasurementRepository $measurements,
        private readonly ResponsibleChoices $responsibles,
        private readonly FindingService $findingService,
        private readonly SectionChoiceBuilder $sectionChoices,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/plan', name: 'app_quality_plan')]
    public function list(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::VIEW_ALL, $centre);

        $year    = $this->tenantContext->getViewYear($centre);
        $filters = [
            'status'  => $request->query->getString('estado'),
            'type'    => $request->query->getString('tipo'),
            'section' => $request->query->getString('proceso'),
            'query'   => $request->query->getString('q'),
        ];
        $today   = $this->clock->now();
        $actions = $year === null ? [] : $this->actions->findPlan($centre, $year, $filters, $today);
        $all     = $year === null ? [] : $this->actions->findPlan($centre, $year);

        return $this->render('quality/plan.html.twig', [
            'centre'    => $centre,
            'year'      => $year,
            'actions'   => $actions,
            'filters'   => $filters,
            'filtered'  => array_filter($filters, static fn (string $v): bool => $v !== '') !== [],
            'summary'   => [
                'total'   => \count($all),
                'done'    => \count(array_filter($all, static fn (ImprovementAction $a): bool => $a->isDone())),
                'overdue' => \count(array_filter($all, static fn (ImprovementAction $a): bool => $a->isOverdue($today))),
            ],
            'today'     => $today,
            'types'     => self::PLAN_TYPES,
            'sections'  => $this->sectionChoices->choices($this->teacher(), $centre),
            'canManage' => $this->isGranted(QualityVoter::MANAGE, $centre),
            'canAdd'    => $this->isGranted(QualityVoter::MANAGE, $centre) && !$this->tenantContext->isViewingNonActiveYear($centre),
        ]);
    }

    #[Route('/plan/nueva', name: 'app_quality_plan_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $year = $centre->getActiveAcademicYear();
        if ($year === null || $this->tenantContext->isViewingNonActiveYear($centre)) {
            throw $this->createAccessDeniedException();
        }

        // Proposed from an off-target indicator value: start from what it's about.
        $measurement = $this->measurementFrom($request, $centre);
        $indicator   = $measurement?->getIndicator();
        $values      = [
            'type'        => ImprovementActionType::Improvement->value,
            'description' => '',
            'goal'        => $indicator === null ? '' : $this->translator->trans('indicator.action_goal', [
                '%indicator%' => $indicator->getName(),
                '%target%'    => $indicator->format($indicator->targetFor($measurement->getPeriod()->getCalendar()->getAcademicYear())?->getTarget()),
            ], 'quality'),
            'section'     => $indicator?->getSection()?->getId()->toRfc4122() ?? '',
            'responsible' => '',
            'dueDate'     => '',
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_plan_action');
            [$values, $errors, $data] = $this->readForm($request, $centre);
            if ($data !== null) {
                $action = $this->findingService->createPlanAction($centre, $year, $this->teacher(), $data['type'], $data['description'], $data['goal'], $data['section'], $data['teacher'], $data['profile'], $data['dueDate'], $measurement);
                $this->addFlash('success', $this->t('plan.flash.added'));

                return $this->redirectToRoute('app_quality_action', ['id' => $action->getId()->toRfc4122()]);
            }
        }

        return $this->renderForm($centre, null, $values, $errors, $measurement);
    }

    #[Route('/acciones/{id}', name: 'app_quality_action')]
    public function show(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $action  = $this->requireAction($id, $centre, QualityVoter::ACTION_VIEW);
        $finding = $action->getFinding();
        if ($finding !== null) {
            return $this->redirectToRoute('app_quality_finding', ['id' => $finding->getId()->toRfc4122(), '_fragment' => 'accion-' . $id]);
        }

        return $this->render('quality/action.html.twig', [
            'centre'    => $centre,
            'action'    => $action,
            'today'     => $this->clock->now(),
            'canWork'   => $this->isGranted(QualityVoter::ACTION_WORK, $action),
            'canManage' => $this->isGranted(QualityVoter::MANAGE, $centre),
            'canList'   => $this->isGranted(QualityVoter::VIEW_ALL, $centre),
        ]);
    }

    #[Route('/acciones/{id}/editar', name: 'app_quality_action_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $action = $this->requirePlanAction($id, $centre);

        $values = [
            'type'        => $action->getType()->value,
            'description' => $action->getDescription(),
            'goal'        => $action->getGoal() ?? '',
            'section'     => $action->getSection()?->getId()->toRfc4122() ?? '',
            'responsible' => ResponsibleChoices::value($action->getResponsibleTeacher(), $action->getResponsibleProfile()),
            'dueDate'     => $action->getDueDate()?->format('Y-m-d') ?? '',
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_plan_action');
            [$values, $errors, $data] = $this->readForm($request, $centre, $action);
            if ($data !== null) {
                $this->findingService->updatePlanAction($action, $data['type'], $data['description'], $data['goal'], $data['section'], $data['teacher'], $data['profile'], $data['dueDate']);
                $this->addFlash('success', $this->t('plan.flash.saved'));

                return $this->redirectToRoute('app_quality_action', ['id' => $id]);
            }
        }

        return $this->renderForm($centre, $action, $values, $errors);
    }

    #[Route('/acciones/{id}/empezar', name: 'app_quality_action_start', methods: ['POST'])]
    public function start(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $action = $this->requireAction($id, $centre, QualityVoter::ACTION_WORK);
        $this->checkToken($request, 'quality_action_' . $id);
        $this->findingService->startAction($action, $this->teacher());

        return $this->redirectToRoute('app_quality_action', ['id' => $id]);
    }

    #[Route('/acciones/{id}/hecha', name: 'app_quality_action_complete', methods: ['POST'])]
    public function complete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $action = $this->requireAction($id, $centre, QualityVoter::ACTION_WORK);
        $this->checkToken($request, 'quality_action_' . $id);
        $result = trim($request->request->getString('result'));
        if ($result === '') {
            $this->addFlash('error', $this->t('action.error.result'));
        } elseif (!$action->isDone()) {
            $this->findingService->completeAction($action, $this->teacher(), $result);
            $this->addFlash('success', $this->t('action.flash.done'));
        }

        return $this->redirectToRoute('app_quality_action', ['id' => $id]);
    }

    #[Route('/acciones/{id}/eliminar', name: 'app_quality_action_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $action = $this->requirePlanAction($id, $centre);
        $this->checkToken($request, 'quality_action_' . $id);
        $this->findingService->deletePlanAction($action);
        $this->addFlash('success', $this->t('plan.flash.deleted'));

        return $this->redirectToRoute('app_quality_plan');
    }

    /**
     * The form's values as sent, its errors, and — when there are none — the data to save.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: ?array{type: ImprovementActionType, description: string, goal: string, section: ?\App\Entity\DocumentSection, teacher: ?Teacher, profile: ?SpecificProfile, dueDate: \DateTimeImmutable}}
     */
    private function readForm(Request $request, EducationalCentre $centre, ?ImprovementAction $editing = null): array
    {
        $values = [];
        foreach (['type', 'description', 'goal', 'section', 'responsible', 'dueDate'] as $field) {
            $values[$field] = trim($request->request->getString($field));
        }
        $errors = [];

        $type = ImprovementActionType::tryFrom($values['type']);
        if ($type === null || !\in_array($type, self::PLAN_TYPES, true)) {
            $errors['type'] = $this->t('action.error.type');
        }
        if ($values['description'] === '') {
            $errors['description'] = $this->t('action.error.description');
        }
        $section = $values['section'] === '' ? null : $this->sections->findByIdAndCentre($values['section'], $centre);

        $responsible = $this->responsibles->resolve($values['responsible'], $centre, $this->teacher(), $editing?->getResponsibleTeacher());
        if ($responsible === null) {
            $errors['responsible'] = $this->t('classify.error.responsible');
        }
        [$teacher, $profile] = $responsible ?? [null, null];

        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['dueDate']);
        if ($dueDate === false) {
            $errors['dueDate'] = $this->t('plan.error.due');
        }

        if ($errors !== [] || $dueDate === false) {
            return [$values, $errors, null];
        }

        return [$values, $errors, [
            'type'        => $type,
            'description' => $values['description'],
            'goal'        => $values['goal'],
            'section'     => $section,
            'teacher'     => $teacher,
            'profile'     => $profile,
            'dueDate'     => $dueDate,
        ]];
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(EducationalCentre $centre, ?ImprovementAction $action, array $values, array $errors, ?Measurement $measurement = null): Response
    {
        return $this->render('quality/plan_form.html.twig', [
            'centre'      => $centre,
            'action'      => $action,
            'values'      => $values,
            'errors'      => $errors,
            'measurement' => $measurement,
            'types'       => self::PLAN_TYPES,
            'sections'    => $this->sectionChoices->choices($this->teacher(), $centre),
            'teachers'    => $this->responsibles->teachers($centre, $action?->getResponsibleTeacher()),
            'profiles'    => $this->responsibles->profiles($centre),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /** The off-target value an action is being proposed from (?medicion= or the form's "measurement"), if any. */
    private function measurementFrom(Request $request, EducationalCentre $centre): ?Measurement
    {
        $id = $request->isMethod('POST') ? $request->request->getString('measurement') : $request->query->getString('medicion');

        return $id === '' ? null : $this->measurements->findByIdAndCentre($id, $centre);
    }

    private function requireAction(string $id, EducationalCentre $centre, string $attribute): ImprovementAction
    {
        $action = $this->actions->findByIdAndCentre($id, $centre);
        if ($action === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted($attribute, $action);

        return $action;
    }

    /** A plan action (not a finding's), for whoever manages the plan. */
    private function requirePlanAction(string $id, EducationalCentre $centre): ImprovementAction
    {
        $action = $this->actions->findByIdAndCentre($id, $centre);
        if ($action === null || !$action->isPlanAction()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);

        return $action;
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
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
