<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileRepository;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/centro/{centreId}/responsabilidades')]
class ResponsibilitiesHubController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly ListItemRepository $listItems,
        private readonly SpecificProfileRepository $specificProfiles,
    ) {}

    #[Route('', name: 'app_responsibilities_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('responsibilities/index.html.twig', [
            'centre' => $centre,
            // What each card shows under its description: how much there is in there.
            'stats'  => [
                'lists'            => \count($this->listItems->findRootsByCentre($centre)),
                'specificProfiles' => \count($this->specificProfiles->findByCentre($centre)),
            ],
        ]);
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);

        return $centre;
    }
}
