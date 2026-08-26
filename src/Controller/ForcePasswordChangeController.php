<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Teacher;
use App\Service\PasswordPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/cambio-contrasena-obligatorio', name: 'app_force_password_change', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_TEACHER')]
class ForcePasswordChangeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TranslatorInterface $translator,
        private readonly PasswordPolicy $passwordPolicy,
    ) {}

    public function __invoke(Request $request): Response
    {
        $teacher = $this->getUser();
        if (!$teacher instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        if ($teacher->isExternal() || !$teacher->isForcePasswordChange()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('force_password_change', $request->request->getString('_csrf_token'))) {
                $errors['general'] = $this->translator->trans('ui.error.invalid_csrf', [], 'messages');
            } else {
                $currentPassword = $request->request->getString('current_password');
                $newPassword     = $request->request->getString('new_password');
                $confirm         = $request->request->getString('new_password_confirm');

                if ($currentPassword === '') {
                    $errors['current_password'] = $this->translator->trans('profile.error.current_password_required', [], 'messages');
                } elseif (!$this->hasher->isPasswordValid($teacher, $currentPassword)) {
                    $errors['current_password'] = $this->translator->trans('profile.error.current_password_invalid', [], 'messages');
                }

                if (($policyViolation = $this->passwordPolicy->firstViolationKey($newPassword)) !== null) {
                    $errors['new_password'] = $this->translator->trans(
                        $policyViolation,
                        ['%min%' => PasswordPolicy::MIN_LENGTH],
                        'messages',
                    );
                }

                if ($newPassword !== $confirm) {
                    $errors['new_password_confirm'] = $this->translator->trans('profile.error.password_mismatch', [], 'messages');
                }

                if (empty($errors)) {
                    $teacher->setPassword($this->hasher->hashPassword($teacher, $newPassword))
                        ->setForcePasswordChange(false);
                    $this->em->flush();

                    $this->addFlash('success', $this->translator->trans('force_password_change.flash.success', [], 'messages'));

                    return $this->redirectToRoute('app_dashboard');
                }
            }
        }

        return $this->render('security/force_password_change.html.twig', [
            'errors' => $errors,
        ]);
    }
}
