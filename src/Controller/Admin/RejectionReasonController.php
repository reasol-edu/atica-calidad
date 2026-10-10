<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\RejectionReasonProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The ready-made reasons a centre's reviewers can reject a submission with (review queue): one per
 * line in a text box; leaving it empty (or "Restablecer") goes back to the standard set.
 */
#[Route('/centro/{centreId}/motivos-de-rechazo')]
class RejectionReasonController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly RejectionReasonProvider $reasons,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_centre_rejection_reasons', methods: ['GET'])]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/rejection_reason/index.html.twig', [
            'centre'     => $centre,
            'reasons'    => $this->reasons->forCentre($centre),
            'customised' => $this->reasons->isCustomised($centre),
            'defaults'   => $this->reasons->defaults(),
        ]);
    }

    #[Route('/guardar', name: 'app_centre_rejection_reasons_save', methods: ['POST'])]
    public function save(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->requireCsrf($centreId, $request);

        $lines = preg_split('/\R/u', $request->request->getString('reasons')) ?: [];
        $this->reasons->replace($centre, $lines);
        $this->addFlash('success', $this->t('rejection_reason.flash.saved'));

        return $this->redirectToRoute('app_centre_rejection_reasons', ['centreId' => $centreId]);
    }

    #[Route('/restablecer', name: 'app_centre_rejection_reasons_reset', methods: ['POST'])]
    public function reset(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->requireCsrf($centreId, $request);

        $this->reasons->replace($centre, []);
        $this->addFlash('success', $this->t('rejection_reason.flash.reset'));

        return $this->redirectToRoute('app_centre_rejection_reasons', ['centreId' => $centreId]);
    }

    private function requireCsrf(string $centreId, Request $request): void
    {
        if (!$this->isCsrfTokenValid('rejection_reasons_' . $centreId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }
}
