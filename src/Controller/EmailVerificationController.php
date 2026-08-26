<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailVerificationController extends AbstractController
{
    #[Route('/perfil/verificar-email/{token}', name: 'app_profile_verify_email', methods: ['GET'])]
    public function __invoke(
        string $token,
        TeacherRepository $repo,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $teacher = $repo->findByEmailVerificationToken($token);

        if ($teacher === null) {
            $this->addFlash('error', $translator->trans('profile.flash.email_verification_invalid', [], 'messages'));

            return $this->redirectToRoute('app_profile');
        }

        if ($teacher->isEmailVerificationTokenExpired()) {
            $teacher->setPendingEmail(null)
                ->setEmailVerificationToken(null)
                ->setEmailVerificationTokenExpiresAt(null);
            $em->flush();
            $this->addFlash('error', $translator->trans('profile.flash.email_verification_expired', [], 'messages'));

            return $this->redirectToRoute('app_profile');
        }

        $teacher->setEmail($teacher->getPendingEmail())
            ->setPendingEmail(null)
            ->setEmailVerificationToken(null)
            ->setEmailVerificationTokenExpiresAt(null);
        $em->flush();
        $this->addFlash('success', $translator->trans('profile.flash.email_verified', [], 'messages'));

        return $this->redirectToRoute('app_profile');
    }
}
