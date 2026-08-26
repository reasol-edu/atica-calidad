<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/centro/{centreId}/registro-avisos')]
class EmailNotificationLogController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
    ) {}

    #[Route('', name: 'app_centre_email_notification_log_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $this->render('admin/email_notification_log/index.html.twig', [
            'centre' => $centre,
        ]);
    }
}
