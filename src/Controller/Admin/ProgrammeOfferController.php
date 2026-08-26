<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/centro/{centreId}/offer')]
class ProgrammeOfferController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
    ) {}

    #[Route('', name: 'app_centre_offer_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/offer/index.html.twig', ['centre' => $centre]);
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }
}
