<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** "Cola de revisión": the page around ReviewQueueComponent. Anyone may open it; it is simply empty for whoever reviews nothing. */
class ReviewQueueController extends AbstractController
{
    #[Route('/revisiones', name: 'app_review_queue')]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        return $this->render('review/queue.html.twig', ['centre' => $centre]);
    }
}
