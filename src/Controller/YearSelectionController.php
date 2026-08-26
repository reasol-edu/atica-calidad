<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Repository\AcademicYearRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TEACHER')]
class YearSelectionController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AcademicYearRepository $years,
    ) {}

    #[Route('/curso/año', name: 'app_select_year_page')]
    public function page(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $returnTo = $request->query->getString('return_to', '/');

        return $this->render('year_selection/index.html.twig', [
            'centre'      => $centre,
            'years'       => $this->years->findByCentreOrderedByName($centre),
            'active_year' => $centre->getActiveAcademicYear(),
            'view_year'   => $this->tenantContext->getViewYear($centre),
            'return_to'   => $returnTo,
        ]);
    }

    #[Route('/curso/año/{id}', name: 'app_select_year', methods: ['POST'],
        requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function select(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('select_year_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->years->findByCentreAndId($centre, $id);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        // Switching to the active year is the same as resetting
        $activeYear = $centre->getActiveAcademicYear();
        if ($activeYear !== null && $year->getId()->toRfc4122() === $activeYear->getId()->toRfc4122()) {
            $this->tenantContext->clearYear();
        } else {
            $this->tenantContext->selectYear($year);
        }

        return $this->redirect($this->resolveReturnTo($request));
    }

    #[Route('/curso/año/activo', name: 'app_reset_year', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre !== null) {
            $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        }

        if (!$this->isCsrfTokenValid('reset_year', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->tenantContext->clearYear();

        return $this->redirect($this->resolveReturnTo($request));
    }

    private function resolveReturnTo(Request $request): string
    {
        $returnTo = $request->request->getString('_return_to', '');

        if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
            return $returnTo;
        }

        return $this->generateUrl('app_dashboard');
    }
}
