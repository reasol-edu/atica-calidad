<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\Audit;
use App\Entity\AuditStatus;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\AuditChecklistTemplateRepository;
use App\Repository\AuditProgramRepository;
use App\Repository\AuditRepository;
use App\Repository\DocumentSectionRepository;
use App\Security\Voter\QualityVoter;
use App\Service\AuditService;
use App\Service\IndicatorBoardBuilder;
use App\Service\MeasurementCalendarTemplates;
use App\Service\PdfRenderer;
use App\Service\ResponsibleChoices;
use App\Service\SectionChoiceBuilder;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Internal audits: the programme of the year being viewed (with its approval and a copy of last
 * year's), defining each audit, preparing it (day, checklist), carrying it out (AuditPerformComponent)
 * and issuing its report, in PDF. The programme is for whoever sees everything in "Mejora
 * continua"; defining audits, for whoever manages it; approving it, for the management team;
 * preparing and carrying out an audit, for its team (QualityVoter). Nothing changes while viewing
 * a past year.
 */
#[Route('/mejora/auditorias')]
class AuditController extends AbstractController
{
    use UploadSizeGuardTrait;

    public function __construct(
        private readonly AuditProgramRepository $programs,
        private readonly AuditRepository $audits,
        private readonly AuditChecklistTemplateRepository $templates,
        private readonly DocumentSectionRepository $sections,
        private readonly AuditService $service,
        private readonly IndicatorBoardBuilder $years,
        private readonly ResponsibleChoices $people,
        private readonly SectionChoiceBuilder $sectionChoices,
        private readonly PdfRenderer $pdf,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_quality_audits')]
    public function program(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::VIEW_ALL, $centre);
        $year     = $this->tenantContext->getViewYear($centre);
        $program  = $year === null ? null : $this->programs->findByYear($year);
        $writable = !$this->tenantContext->isViewingNonActiveYear($centre);
        $previous = $year === null || ($program !== null && !$program->getAudits()->isEmpty()) ? null : $this->years->previousYear($centre, $year);

        $conflicts = [];
        foreach ($program?->getAudits() ?? [] as $audit) {
            $conflicts[$audit->getId()->toRfc4122()] = $this->service->independenceConflicts($audit->getScope(), $audit->getTeam());
        }

        return $this->render('quality/audits.html.twig', [
            'centre'     => $centre,
            'year'       => $year,
            'program'    => $program,
            'months'     => $year === null ? [] : $this->months($year),
            'conflicts'  => $conflicts,
            'writable'   => $writable,
            'canManage'  => $this->isGranted(QualityVoter::MANAGE, $centre),
            'canApprove' => $this->isGranted(QualityVoter::AUDIT_APPROVE, $centre),
            'previous'   => $previous !== null && ($this->programs->findByYear($previous)?->getAudits()->isEmpty() === false) ? $previous : null,
        ]);
    }

    #[Route('/aprobar', name: 'app_quality_audits_approve', methods: ['POST'])]
    public function approve(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::AUDIT_APPROVE, $centre);
        $this->checkToken($request, 'quality_audit_program');
        $program = $this->programs->findByYear($this->writableYear($centre));
        if ($program === null || $program->getAudits()->isEmpty()) {
            throw $this->createNotFoundException();
        }
        $this->service->approve($program, $this->teacher());
        $this->addFlash('success', $this->t('audit.flash.approved'));

        return $this->redirectToRoute('app_quality_audits');
    }

    #[Route('/copiar', name: 'app_quality_audits_copy', methods: ['POST'])]
    public function copy(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $this->checkToken($request, 'quality_audit_program');
        $year     = $this->writableYear($centre);
        $previous = $this->years->previousYear($centre, $year) ?? throw $this->createNotFoundException();
        $copied   = $this->service->copyProgram($centre, $previous, $year);
        $this->addFlash('success', $this->translator->trans('audit.flash.copied', ['%count%' => $copied, '%year%' => $previous->getName()], 'quality'));

        return $this->redirectToRoute('app_quality_audits');
    }

    #[Route('/programa.pdf', name: 'app_quality_audits_pdf')]
    public function programPdf(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::VIEW_ALL, $centre);
        $year    = $this->tenantContext->getViewYear($centre) ?? throw $this->createNotFoundException();
        $program = $this->programs->findByYear($year) ?? throw $this->createNotFoundException();

        return $this->pdf->render('quality/pdf/audit_program.html.twig', [
            'centre'  => $centre,
            'program' => $program,
        ], $this->t('audit.program_title') . ' ' . $year->getName(), 'programa-de-auditorias-' . $year->getName() . '.pdf', inline: true, orientation: 'L', centre: $centre, reportType: 'audit_program');
    }

    #[Route('/nueva', name: 'app_quality_audit_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $year   = $this->writableYear($centre);
        $values = ['title' => '', 'objective' => '', 'scope' => [], 'plannedMonth' => '', 'lead' => '', 'auditors' => []];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_audit_form');
            [$values, $errors, $data] = $this->readAuditForm($request, $centre, $year, null);
            if ($data !== null) {
                $audit = $this->service->saveAudit($centre, $year, null, $data);
                $this->flashConflicts($audit);
                $this->addFlash('success', $this->t('audit.flash.added'));

                return $this->redirectToRoute('app_quality_audit', ['id' => $audit->getId()->toRfc4122()]);
            }
        }

        return $this->renderAuditForm($centre, $year, null, $values, $errors);
    }

    #[Route('/{id}', name: 'app_quality_audit', requirements: ['id' => Requirement::UUID])]
    public function show(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $audit = $this->requireAudit($id, $centre, QualityVoter::AUDIT_VIEW);

        return $this->render('quality/audit.html.twig', [
            'centre'    => $centre,
            'audit'     => $audit,
            'auditees'  => $this->service->auditees($audit),
            'conflicts' => $this->service->independenceConflicts($audit->getScope(), $audit->getTeam()),
            'findings'  => $this->service->findingsOf($audit),
            'steps'     => $this->service->nextSteps($audit),
            'blockers'  => ['start' => $this->service->blockers($audit, 'start')],
            'canWork'   => $this->canWrite($audit, QualityVoter::AUDIT_WORK),
            'canManage' => $this->isGranted(QualityVoter::MANAGE, $centre) && !$this->tenantContext->isViewingNonActiveYear($centre),
            'canList'   => $this->isGranted(QualityVoter::VIEW_ALL, $centre),
        ]);
    }

    #[Route('/{id}/editar', name: 'app_quality_audit_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $audit = $this->requireAudit($id, $centre, QualityVoter::AUDIT_VIEW);
        $year  = $this->writableYear($centre);
        if (!$audit->getStatus()->isPending()) {
            throw $this->createAccessDeniedException();
        }
        $values = [
            'title'        => $audit->getTitle(),
            'objective'    => $audit->getObjective() ?? '',
            'scope'        => array_map(static fn (DocumentSection $s): string => $s->getId()->toRfc4122(), $audit->getScope()->toArray()),
            'plannedMonth' => $audit->getPlannedMonth()->format('Y-m'),
            'lead'         => $audit->getLeadAuditor()?->getId()->toRfc4122() ?? '',
            'auditors'     => array_map(static fn (Teacher $t): string => $t->getId()->toRfc4122(), $audit->getAuditors()->toArray()),
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_audit_form');
            [$values, $errors, $data] = $this->readAuditForm($request, $centre, $year, $audit);
            if ($data !== null) {
                $this->service->saveAudit($centre, $year, $audit, $data);
                $this->flashConflicts($audit);
                $this->addFlash('success', $this->t('audit.flash.saved'));

                return $this->redirectToRoute('app_quality_audit', ['id' => $id]);
            }
        }

        return $this->renderAuditForm($centre, $year, $audit, $values, $errors);
    }

    #[Route('/{id}/eliminar', name: 'app_quality_audit_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $audit = $this->requireAudit($id, $centre, QualityVoter::AUDIT_VIEW);
        $this->checkToken($request, 'quality_audit_' . $id);
        $this->writableYear($centre);
        if (!\in_array($audit->getStatus(), [AuditStatus::Planned, AuditStatus::Preparation], true)) {
            throw $this->createAccessDeniedException();
        }
        $this->service->deleteAudit($audit);
        $this->addFlash('success', $this->t('audit.flash.deleted'));

        return $this->redirectToRoute('app_quality_audits');
    }

    // ── Preparing ────────────────────────────────────────────────────────────

    #[Route('/{id}/preparar', name: 'app_quality_audit_prepare', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function prepare(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $audit = $this->requireWorkable($id, $centre, [AuditStatus::Planned, AuditStatus::Preparation]);

        $values = [
            'date'      => $audit->getScheduledAt()?->format('Y-m-d') ?? '',
            'time'      => $audit->getScheduledAt()?->format('H:i') ?? '',
            'objective' => $audit->getObjective() ?? '',
        ];
        $rows = [];
        foreach ($audit->getItems() as $item) {
            $rows[] = ['id' => $item->getId()->toRfc4122(), 'clause' => $item->getClause() ?? '', 'question' => $item->getQuestion(), 'guidance' => $item->getGuidance() ?? ''];
        }
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_audit_' . $id);
            [$values, $rows, $errors, $data] = $this->readPreparation($request, $audit);
            if ($data !== null) {
                $this->service->savePreparation($audit, $this->teacher(), $data['scheduledAt'], $values['objective'], $data['rows']);
                $this->addFlash('success', $this->t('audit.flash.prepared'));

                return $this->redirectToRoute('app_quality_audit', ['id' => $id]);
            }
        }

        return $this->render('quality/audit_prepare.html.twig', [
            'centre'    => $centre,
            'audit'     => $audit,
            'values'    => $values,
            'rows'      => $rows,
            'errors'    => $errors,
            'templates' => $this->templates->findByCentre($centre),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/{id}/lista', name: 'app_quality_audit_add_checklist', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function addChecklist(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $audit = $this->requireWorkable($id, $centre, [AuditStatus::Planned, AuditStatus::Preparation]);
        $this->checkToken($request, 'quality_audit_' . $id);
        $template = $this->templates->findByIdAndCentre($request->request->getString('template'), $centre) ?? throw $this->createNotFoundException();
        $this->service->addChecklist($audit, $this->teacher(), $template);
        $this->addFlash('success', $this->translator->trans('audit.flash.checklist_added', ['%name%' => $template->getName(), '%count%' => \count($template->getItems())], 'quality'));

        return $this->redirectToRoute('app_quality_audit_prepare', ['id' => $id]);
    }

    #[Route('/{id}/empezar', name: 'app_quality_audit_start', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function start(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $audit = $this->requireWorkable($id, $centre, [AuditStatus::Preparation]);
        $this->checkToken($request, 'quality_audit_' . $id);
        $blockers = $this->service->blockers($audit, 'start');
        if ($blockers !== []) {
            $this->addFlash('error', implode(' ', $blockers));

            return $this->redirectToRoute('app_quality_audit', ['id' => $id]);
        }
        $this->service->start($audit, $this->teacher());

        return $this->redirectToRoute('app_quality_audit_perform', ['id' => $id]);
    }

    // ── Carrying it out ──────────────────────────────────────────────────────

    #[Route('/{id}/realizar', name: 'app_quality_audit_perform', requirements: ['id' => Requirement::UUID])]
    public function perform(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $audit = $this->requireWorkable($id, $centre, [AuditStatus::InProgress]);

        return $this->render('quality/audit_perform.html.twig', ['centre' => $centre, 'audit' => $audit]);
    }

    #[Route('/puntos/{itemId}/adjuntos', name: 'app_quality_audit_item_attach', requirements: ['itemId' => Requirement::UUID], methods: ['POST'])]
    public function attach(string $itemId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $item  = $this->audits->findItemByIdAndCentre($itemId, $centre) ?? throw $this->createNotFoundException();
        $audit = $this->requireWorkable($item->getAudit()->getId()->toRfc4122(), $centre, [AuditStatus::InProgress]);
        $back  = $this->redirectToRoute('app_quality_audit_perform', ['id' => $audit->getId()->toRfc4122(), '_fragment' => 'punto-' . $itemId]);
        if ($this->isUploadTooLarge($request)) {
            $this->addFlash('error', $this->translator->trans('report.error.too_large', [], 'quality'));

            return $back;
        }
        $this->checkToken($request, 'quality_audit_item_' . $itemId);

        $files = array_values(array_filter($request->files->all('files'), static fn (mixed $f): bool => $f instanceof UploadedFile && $f->isValid() && $f->getSize() <= QualityController::MAX_FILE_SIZE));
        if ($files === [] || \count($files) > QualityController::MAX_FILES) {
            $this->addFlash('error', $this->translator->trans('attachment.error.none', [], 'quality'));

            return $back;
        }
        foreach ($files as $file) {
            $this->service->attach($item, $this->teacher(), $file);
        }
        $this->addFlash('success', $this->translator->trans('attachment.flash.added', ['%count%' => \count($files)], 'quality'));

        return $back;
    }

    #[Route('/{id}/emitir', name: 'app_quality_audit_issue', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function issue(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $audit = $this->requireWorkable($id, $centre, [AuditStatus::InProgress]);
        $this->checkToken($request, 'quality_audit_' . $id);
        $blockers = $this->service->blockers($audit, 'issue_report');
        if ($blockers !== []) {
            $this->addFlash('error', implode(' ', $blockers));

            return $this->redirectToRoute('app_quality_audit_perform', ['id' => $id]);
        }
        $raised = $this->service->issueReport($audit, $this->teacher());
        $this->addFlash('success', $this->translator->trans('audit.flash.issued', ['%count%' => \count($raised)], 'quality'));

        return $this->redirectToRoute('app_quality_audit', ['id' => $id]);
    }

    #[Route('/{id}/informe.pdf', name: 'app_quality_audit_report', requirements: ['id' => Requirement::UUID])]
    public function report(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $audit = $this->requireAudit($id, $centre, QualityVoter::AUDIT_VIEW);
        if ($audit->getStatus()->isPending()) {
            throw $this->createNotFoundException();
        }

        return $this->pdf->render('quality/pdf/audit_report.html.twig', [
            'centre'   => $centre,
            'audit'    => $audit,
            'auditees' => $this->service->auditees($audit),
            'findings' => $this->service->findingsOf($audit),
        ], $this->t('audit.report_title') . ' ' . $audit->getCode(), 'informe-auditoria-' . $audit->getCode() . '.pdf', inline: true, orientation: 'P', centre: $centre, reportType: 'audit_report');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: array{title: string, objective: string, scope: list<string>, plannedMonth: string, lead: string, auditors: list<string>}, 1: array<string, string>, 2: ?array{title: string, objective: ?string, scope: list<DocumentSection>, plannedMonth: \DateTimeImmutable, lead: ?Teacher, auditors: list<Teacher>}}
     */
    private function readAuditForm(Request $request, EducationalCentre $centre, AcademicYear $year, ?Audit $editing): array
    {
        $values = [
            'title'        => trim($request->request->getString('title')),
            'objective'    => trim($request->request->getString('objective')),
            'scope'        => array_values(array_filter($request->request->all('scope'), 'is_string')),
            'plannedMonth' => $request->request->getString('plannedMonth'),
            'lead'         => $request->request->getString('lead'),
            'auditors'     => array_values(array_filter($request->request->all('auditors'), 'is_string')),
        ];
        $errors = [];
        if ($values['title'] === '') {
            $errors['title'] = $this->t('audit.error.title');
        }

        $scope = [];
        foreach ($values['scope'] as $sectionId) {
            $section = $this->sections->findByIdAndCentre($sectionId, $centre);
            if ($section !== null) {
                $scope[] = $section;
            }
        }
        if ($scope === []) {
            $errors['scope'] = $this->t('audit.error.scope');
        }

        $month = \in_array($values['plannedMonth'], array_keys($this->months($year)), true) ? \DateTimeImmutable::createFromFormat('!Y-m', $values['plannedMonth']) : false;
        if ($month === false) {
            $errors['plannedMonth'] = $this->t('audit.error.month');
        }

        $byId = [];
        foreach ($this->people->teachers($centre, $editing?->getLeadAuditor()) as $teacher) {
            $byId[$teacher->getId()->toRfc4122()] = $teacher;
        }
        foreach ($editing?->getAuditors() ?? [] as $teacher) {
            $byId[$teacher->getId()->toRfc4122()] ??= $teacher;
        }
        $lead = $byId[$values['lead']] ?? null;
        if ($values['lead'] !== '' && $lead === null) {
            $errors['lead'] = $this->t('classify.error.responsible');
        }
        $auditors = array_values(array_filter(array_map(static fn (string $id): ?Teacher => $byId[$id] ?? null, $values['auditors'])));

        if ($errors !== [] || $month === false) {
            return [$values, $errors, null];
        }

        return [$values, $errors, [
            'title'        => $values['title'],
            'objective'    => $values['objective'],
            'scope'        => $scope,
            'plannedMonth' => $month,
            'lead'         => $lead,
            'auditors'     => $auditors,
        ]];
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function renderAuditForm(EducationalCentre $centre, AcademicYear $year, ?Audit $audit, array $values, array $errors): Response
    {
        return $this->render('quality/audit_form.html.twig', [
            'centre'   => $centre,
            'year'     => $year,
            'audit'    => $audit,
            'values'   => $values,
            'errors'   => $errors,
            'sections' => $this->sectionChoices->choices($this->teacher(), $centre),
            'months'   => $this->months($year),
            'teachers' => $this->people->teachers($centre, $audit?->getLeadAuditor()),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /**
     * The preparation form: the day and time, the objective, and the checklist rows. A row left
     * blank is ignored; ticking "remove" removes it.
     *
     * @return array{0: array{date: string, time: string, objective: string}, 1: list<array{id: string, clause: string, question: string, guidance: string}>, 2: array<string, string>, 3: ?array{scheduledAt: ?\DateTimeImmutable, rows: list<array{id: ?string, clause: ?string, question: string, guidance: ?string}>}}
     */
    private function readPreparation(Request $request, Audit $audit): array
    {
        $values = [
            'date'      => $request->request->getString('date'),
            'time'      => $request->request->getString('time'),
            'objective' => trim($request->request->getString('objective')),
        ];
        $errors      = [];
        $scheduledAt = null;
        if ($values['date'] !== '') {
            $scheduledAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $values['date'] . ' ' . ($values['time'] !== '' ? $values['time'] : '09:00'));
            if ($scheduledAt === false) {
                $errors['date'] = $this->t('audit.error.date');
                $scheduledAt    = null;
            }
        }

        $own = [];
        foreach ($audit->getItems() as $item) {
            $own[$item->getId()->toRfc4122()] = true;
        }
        $rows  = [];
        $clean = [];
        foreach ($request->request->all('items') as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $row = [
                'id'       => \is_string($raw['id'] ?? null) ? $raw['id'] : '',
                'clause'   => \is_string($raw['clause'] ?? null) ? trim($raw['clause']) : '',
                'question' => \is_string($raw['question'] ?? null) ? trim($raw['question']) : '',
                'guidance' => \is_string($raw['guidance'] ?? null) ? trim($raw['guidance']) : '',
            ];
            if (($raw['remove'] ?? '') === '1' || ($row['question'] === '' && $row['clause'] === '' && $row['guidance'] === '')) {
                continue;
            }
            $rows[] = $row;
            if ($row['question'] === '') {
                $errors['items'] = $this->t('audit.error.question');

                continue;
            }
            $clean[] = [
                'id'       => isset($own[$row['id']]) ? $row['id'] : null,
                'clause'   => $row['clause'] !== '' ? mb_substr($row['clause'], 0, 20) : null,
                'question' => $row['question'],
                'guidance' => $row['guidance'] !== '' ? $row['guidance'] : null,
            ];
        }

        return [$values, $rows, $errors, $errors === [] ? ['scheduledAt' => $scheduledAt, 'rows' => $clean] : null];
    }

    /** Warns, without stopping anything, that someone would audit their own process. */
    private function flashConflicts(Audit $audit): void
    {
        foreach ($this->service->independenceConflicts($audit->getScope(), $audit->getTeam()) as $conflict) {
            $this->addFlash('error', $this->translator->trans('audit.independence', [
                '%name%'     => $conflict['teacher']->getName()->getFirstName() . ' ' . $conflict['teacher']->getName()->getLastName(),
                '%sections%' => implode(', ', array_map(static fn (DocumentSection $s): string => $s->getName(), $conflict['sections'])),
            ], 'quality'));
        }
    }

    /**
     * The months of $year's programme, September to July, as "2026-09" => "septiembre de 2026".
     *
     * @return array<string, string>
     */
    private function months(AcademicYear $year): array
    {
        $first  = MeasurementCalendarTemplates::firstYear($year, $this->clock->now());
        $months = [];
        for ($i = 0; $i < 11; ++$i) {
            $date                            = (new \DateTimeImmutable())->setDate($first, 9, 1)->setTime(0, 0)->modify('+' . $i . ' months');
            $months[$date->format('Y-m')] = $this->translator->trans('month.' . $date->format('n'), [], 'calendar') . ' ' . $date->format('Y');
        }

        return $months;
    }

    /** The active year, when it's the one being viewed: nothing is written in a past one. */
    private function writableYear(EducationalCentre $centre): AcademicYear
    {
        $year = $centre->getActiveAcademicYear();
        if ($year === null || $this->tenantContext->isViewingNonActiveYear($centre)) {
            throw $this->createAccessDeniedException();
        }

        return $year;
    }

    private function canWrite(Audit $audit, string $attribute): bool
    {
        return $this->isGranted($attribute, $audit) && !$this->tenantContext->isViewingNonActiveYear($audit->getEducationalCentre());
    }

    private function requireAudit(string $id, EducationalCentre $centre, string $attribute): Audit
    {
        $audit = $this->audits->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted($attribute, $audit);

        return $audit;
    }

    /**
     * An audit its team (or whoever manages) can work on, in one of $statuses, in the active year.
     *
     * @param list<AuditStatus> $statuses
     */
    private function requireWorkable(string $id, EducationalCentre $centre, array $statuses): Audit
    {
        $audit = $this->requireAudit($id, $centre, QualityVoter::AUDIT_WORK);
        $this->writableYear($centre);
        if (!\in_array($audit->getStatus(), $statuses, true)) {
            throw $this->createAccessDeniedException();
        }

        return $audit;
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
