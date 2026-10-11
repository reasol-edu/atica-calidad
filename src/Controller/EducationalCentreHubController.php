<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Repository\AcademicYearRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\NonWorkingDayRepository;
use App\Repository\RejectionReasonRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/centro/{centreId}')]
class EducationalCentreHubController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly AcademicYearRepository $years,
        private readonly TeacherRepository $teachers,
        private readonly NonWorkingDayRepository $nonWorkingDays,
        private readonly RejectionReasonRepository $rejectionReasons,
    ) {}

    #[Route('', name: 'app_centre_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $centre->getActiveAcademicYear();

        return $this->render('educational_centre/index.html.twig', [
            'centre' => $centre,
            // What each card shows under its description: how much there is in there. The ones
            // that depend on the active year are null without one (the card says so itself).
            'stats'  => [
                'years'            => \count($this->years->findByCentreOrderedByName($centre)),
                'teachers'         => $year !== null ? $this->teachers->countByAcademicYear($year) : null,
                'nonWorkingDays'   => $year !== null ? \count($this->nonWorkingDays->findByAcademicYearOrdered($year)) : null,
                'rejectionReasons' => \count($this->rejectionReasons->findByCentre($centre)),
            ],
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
