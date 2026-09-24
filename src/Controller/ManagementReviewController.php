<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\ManagementReview;
use App\Entity\Teacher;
use App\Repository\ImprovementActionRepository;
use App\Repository\ManagementReviewRepository;
use App\Security\Voter\QualityVoter;
use App\Service\ManagementReviewBuilder;
use App\Service\ManagementReviewService;
use App\Service\PdfRenderer;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The management review (ISO 9001 9.3): the centre's reviews, most recent first; scheduling one
 * (in the active year), the review itself — what the application compiles for its period, what
 * the management team writes and its decisions, which are improvement plan actions — closing it,
 * and its minutes in PDF. Reading is for whoever sees everything in "Mejora continua"; preparing
 * it and recording its decisions, for whoever manages it; closing it, for the management team
 * (QualityVoter).
 */
#[Route('/mejora/revisiones')]
class ManagementReviewController extends AbstractController
{
    /** The texts the management team writes, as named in the form. */
    private const array TEXTS = ['attendees', 'contextChanges', 'satisfaction', 'suppliers', 'resources', 'conclusions'];

    public function __construct(
        private readonly ManagementReviewRepository $reviews,
        private readonly ImprovementActionRepository $actions,
        private readonly ManagementReviewService $service,
        private readonly ManagementReviewBuilder $builder,
        private readonly PdfRenderer $pdf,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_quality_reviews')]
    public function list(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::VIEW_ALL, $centre);

        return $this->render('quality/reviews.html.twig', [
            'centre'  => $centre,
            'reviews' => $this->reviews->findByCentre($centre),
            'canAdd'  => $this->canAdd($centre),
        ]);
    }

    #[Route('/nueva', name: 'app_quality_review_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $year = $centre->getActiveAcademicYear();
        if ($year === null || !$this->canAdd($centre)) {
            throw $this->createAccessDeniedException();
        }

        [$start, $end] = $this->builder->defaultPeriod($centre);
        $values        = [
            'title'       => $this->translator->trans('review.default_title', ['%year%' => $year->getName()], 'quality'),
            'heldOn'      => $this->clock->now()->format('Y-m-d'),
            'periodStart' => $start->format('Y-m-d'),
            'periodEnd'   => $end->format('Y-m-d'),
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_review');
            [$values, $errors, $data] = $this->readSchedule($request);
            if ($data !== null) {
                $review = $this->service->create($centre, $year, $this->teacher(), $data['title'], $data['heldOn'], $data['periodStart'], $data['periodEnd']);
                $this->addFlash('success', $this->t('review.flash.created'));

                return $this->redirectToRoute('app_quality_review', ['id' => $review->getId()->toRfc4122()]);
            }
        }

        return $this->render('quality/review_form.html.twig', [
            'centre' => $centre,
            'review' => null,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/{id}', name: 'app_quality_review', requirements: ['id' => Requirement::UUID])]
    public function show(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $review    = $this->requireReview($id, $centre);
        $canManage = !$review->isClosed() && $this->isGranted(QualityVoter::MANAGE, $centre);

        return $this->render('quality/review.html.twig', [
            'centre'    => $centre,
            'review'    => $review,
            'inputs'    => $this->builder->inputsOf($review),
            'decisions' => $this->actions->findByManagementReview($review),
            'today'     => $this->clock->now(),
            'canManage' => $canManage,
            // Decisions go into the active year's plan: not while looking at a past year.
            'canDecide' => $canManage && $this->canAdd($centre),
            'canClose'  => !$review->isClosed() && $this->isGranted(QualityVoter::REVIEW_CLOSE, $centre),
            'blockers'  => $review->isClosed() ? [] : $this->service->blockers($review),
        ]);
    }

    #[Route('/{id}/editar', name: 'app_quality_review_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $review = $this->requireOpenReview($id, $centre);

        $values = [
            'title'          => $review->getTitle(),
            'heldOn'         => $review->getHeldOn()->format('Y-m-d'),
            'periodStart'    => $review->getPeriodStart()->format('Y-m-d'),
            'periodEnd'      => $review->getPeriodEnd()->format('Y-m-d'),
            'attendees'      => $review->getAttendees() ?? '',
            'contextChanges' => $review->getContextChanges() ?? '',
            'satisfaction'   => $review->getSatisfaction() ?? '',
            'suppliers'      => $review->getSuppliers() ?? '',
            'resources'      => $review->getResources() ?? '',
            'conclusions'    => $review->getConclusions() ?? '',
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_review');
            [$values, $errors, $data] = $this->readSchedule($request);
            foreach (self::TEXTS as $field) {
                $values[$field] = trim($request->request->getString($field));
            }
            if ($data !== null) {
                $this->service->save($review, $data['title'], $data['heldOn'], $data['periodStart'], $data['periodEnd'], $values['attendees'], $values['contextChanges'], $values['satisfaction'], $values['suppliers'], $values['resources'], $values['conclusions']);
                $this->addFlash('success', $this->t('review.flash.saved'));

                return $this->redirectToRoute('app_quality_review', ['id' => $id]);
            }
        }

        return $this->render('quality/review_form.html.twig', [
            'centre' => $centre,
            'review' => $review,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/{id}/cerrar', name: 'app_quality_review_close', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function close(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $review = $this->requireReview($id, $centre);
        $this->denyAccessUnlessGranted(QualityVoter::REVIEW_CLOSE, $centre);
        $this->checkToken($request, 'quality_review_' . $id);
        if ($review->isClosed() || $this->service->blockers($review) !== []) {
            $this->addFlash('error', $this->t('step.error.not_now'));
        } else {
            $this->service->close($review, $this->teacher());
            $this->addFlash('success', $this->t('review.flash.closed'));
        }

        return $this->redirectToRoute('app_quality_review', ['id' => $id]);
    }

    #[Route('/{id}/eliminar', name: 'app_quality_review_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $review = $this->requireOpenReview($id, $centre);
        $this->checkToken($request, 'quality_review_' . $id);
        $this->service->delete($review);
        $this->addFlash('success', $this->t('review.flash.deleted'));

        return $this->redirectToRoute('app_quality_reviews');
    }

    #[Route('/{id}/acta.pdf', name: 'app_quality_review_pdf', requirements: ['id' => Requirement::UUID])]
    public function minutes(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $review = $this->requireReview($id, $centre);

        return $this->pdf->render('quality/pdf/management_review.html.twig', [
            'centre'    => $centre,
            'review'    => $review,
            'inputs'    => $this->builder->inputsOf($review),
            'decisions' => $this->actions->findByManagementReview($review),
            'today'     => $this->clock->now(),
        ], $review->getTitle(), 'revision-por-la-direccion-' . $review->getHeldOn()->format('Y-m-d') . '.pdf', inline: true, draftWatermark: !$review->isClosed(), orientation: 'P', centre: $centre, reportType: 'management_review');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The schedule as sent, its errors, and — when there are none — the data to save.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: ?array{title: string, heldOn: \DateTimeImmutable, periodStart: \DateTimeImmutable, periodEnd: \DateTimeImmutable}}
     */
    private function readSchedule(Request $request): array
    {
        $values = [];
        foreach (['title', 'heldOn', 'periodStart', 'periodEnd'] as $field) {
            $values[$field] = trim($request->request->getString($field));
        }
        $errors = [];

        if ($values['title'] === '') {
            $errors['title'] = $this->t('review.error.title');
        }
        $dates = [];
        foreach (['heldOn', 'periodStart', 'periodEnd'] as $field) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $values[$field]);
            if ($date === false) {
                $errors[$field] = $this->t('error.date');
            } else {
                $dates[$field] = $date;
            }
        }
        if (isset($dates['periodStart'], $dates['periodEnd']) && $dates['periodEnd'] < $dates['periodStart']) {
            $errors['periodEnd'] = $this->t('review.error.period');
        }

        if ($errors !== [] || !isset($dates['heldOn'], $dates['periodStart'], $dates['periodEnd'])) {
            return [$values, $errors, null];
        }

        return [$values, $errors, [
            'title'       => $values['title'],
            'heldOn'      => $dates['heldOn'],
            'periodStart' => $dates['periodStart'],
            'periodEnd'   => $dates['periodEnd'],
        ]];
    }

    /** New reviews (and their decisions) go into the active year: not while looking at a past one. */
    private function canAdd(EducationalCentre $centre): bool
    {
        return $this->isGranted(QualityVoter::MANAGE, $centre) && !$this->tenantContext->isViewingNonActiveYear($centre);
    }

    private function requireReview(string $id, EducationalCentre $centre): ManagementReview
    {
        $this->denyAccessUnlessGranted(QualityVoter::VIEW_ALL, $centre);
        $review = $this->reviews->findByIdAndCentre($id, $centre);
        if ($review === null) {
            throw $this->createNotFoundException();
        }

        return $review;
    }

    /** A review still open, for whoever manages "Mejora continua". */
    private function requireOpenReview(string $id, EducationalCentre $centre): ManagementReview
    {
        $review = $this->requireReview($id, $centre);
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        if ($review->isClosed()) {
            throw $this->createAccessDeniedException();
        }

        return $review;
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
