<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly EducationalCentreRepository $centres,
    ) {}

    #[Route('/admin', name: 'app_admin')]
    public function index(): Response
    {
        return $this->render('admin/index.html.twig', [
            'stats' => [
                'teachers_total'  => $this->teachers->countAll(),
                'teachers_active' => $this->teachers->countActive(),
                'teachers_admin'  => $this->teachers->countAdmins(),
                'centres_total'   => $this->centres->countAll(),
            ],
        ]);
    }
}
