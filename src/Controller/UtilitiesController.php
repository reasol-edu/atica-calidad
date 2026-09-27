<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Utilidades": personal tools that don't belong to the quality system itself — today, just the
 * calendar generator (CalendarGeneratorController). A hub of cards, like Informes
 * (ReportsController), so more tools can be added later without a new top-level menu entry.
 */
#[Route('/utilidades')]
class UtilitiesController extends AbstractController
{
    #[Route('', name: 'app_utilities_index')]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        return $this->render('utilities/index.html.twig', [
            'centre' => $centre,
        ]);
    }
}
