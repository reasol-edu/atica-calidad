<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Utilidades": personal tools that don't belong to the quality system itself. A hub of cards, like
 * Informes (ReportsController), so tools can be added without a new top-level menu entry.
 *
 * It is EMPTY for now: the calendar generator and the date calculator moved to another application,
 * so its menu entry is commented out in templates/layouts/app.html.twig. When a tool comes back,
 * add its card to templates/utilities/index.html.twig and restore that menu entry.
 */
#[Route('/utilidades')]
class UtilitiesController extends AbstractController
{
    #[Route('', name: 'app_utilities_index')]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        return $this->render('utilities/index.html.twig', ['centre' => $centre]);
    }
}
