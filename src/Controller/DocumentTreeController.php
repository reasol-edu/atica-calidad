<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DocumentTreeController extends AbstractController
{
    #[Route('/arbol-documental', name: 'app_document_tree')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $canEdit      = $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $requestedTab = $request->query->getString('tab');
        $tab          = $canEdit && $requestedTab === 'edit' ? 'edit' : 'view';

        return $this->render('document_tree/index.html.twig', [
            'centre'  => $centre,
            'canEdit' => $canEdit,
            'tab'     => $tab,
        ]);
    }
}
