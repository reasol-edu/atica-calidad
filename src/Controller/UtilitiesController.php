<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\PrintableCalendarRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Utilidades": personal tools that don't belong to the quality system itself — the calendar
 * generator (CalendarGeneratorController) and the date calculator (DateCalculatorController). A
 * hub of cards, like Informes (ReportsController), so more tools can be added later without a new
 * top-level menu entry.
 */
#[Route('/utilidades')]
class UtilitiesController extends AbstractController
{
    public function __construct(
        private readonly PrintableCalendarRepository $calendars,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('', name: 'app_utilities_index')]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        // The calendars the person has saved for the year they are looking at (the same one the
        // generator's own list opens on).
        $user = $this->getUser();
        $year = $this->tenantContext->getViewYear($centre) ?? $centre->getActiveAcademicYear();

        return $this->render('utilities/index.html.twig', [
            'centre' => $centre,
            'stats'  => [
                'calendars' => $user instanceof Teacher && $year !== null ? \count($this->calendars->findByOwnerAndYear($user, $year)) : null,
            ],
        ]);
    }
}
