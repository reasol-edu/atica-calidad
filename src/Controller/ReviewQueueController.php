<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Service\Form\FormApprovalChain;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Cola de revisión": the page around ReviewQueueComponent, plus the steps of the approval chains of filled documents that wait for
 * this person. Anyone may open it; it is simply empty for whoever reviews nothing.
 */
class ReviewQueueController extends AbstractController
{
    public function __construct(
        private readonly FormApprovalChain $chain,
    ) {}

    #[Route('/revisiones', name: 'app_review_queue')]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $user = $this->getUser();

        return $this->render('review/queue.html.twig', [
            'centre' => $centre,
            'steps'  => $user instanceof Teacher ? $this->chain->pendingFor($user, $centre) : [],
        ]);
    }
}
