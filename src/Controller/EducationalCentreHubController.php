<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/centro/{centreId}')]
class EducationalCentreHubController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
    ) {}

    #[Route('', name: 'app_centre_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('educational_centre/index.html.twig', [
            'centre' => $centre,
        ]);
    }

    #[Route('/ajustes', name: 'app_centre_settings')]
    public function settings(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('educational_centre/settings.html.twig', [
            'centre' => $centre,
        ]);
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
