<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/ajustes', name: 'app_admin_settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('admin/settings/index.html.twig');
    }
}
