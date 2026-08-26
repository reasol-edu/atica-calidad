<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CentreSelectionController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/seleccion/centro', name: 'app_select_centre')]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('centre_selection/index.html.twig', [
            'centres' => $this->centres->findAccessibleByTeacher($user),
        ]);
    }

    #[Route('/seleccion/centro/{id}', name: 'app_select_centre_choose', methods: ['POST'])]
    public function choose(string $id, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('select_centre_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $centre = $this->centres->findById($id);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $accessible = $this->centres->findAccessibleByTeacher($user);
        $ids = array_map(static fn($c) => $c->getId()->toRfc4122(), $accessible);

        if (!\in_array($id, $ids, strict: true)) {
            throw $this->createAccessDeniedException();
        }

        $this->tenantContext->selectCentre($centre);

        return $this->redirectToRoute('app_dashboard');
    }
}
