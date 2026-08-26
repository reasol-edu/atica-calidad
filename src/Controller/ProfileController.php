<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Service\PasswordPolicy;
use App\Service\ProfileMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/perfil')]
#[IsGranted('ROLE_TEACHER')]
class ProfileController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TranslatorInterface $translator,
        private readonly ProfileMailer $profileMailer,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TeacherRepository $teachers,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('', name: 'app_profile')]
    public function edit(Request $request): Response
    {
        $teacher = $this->getUser();
        if (!$teacher instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $errors = [];
        $values = [
            'first_name'           => $teacher->getName()->getFirstName(),
            'last_name'            => $teacher->getName()->getLastName(),
            'email'                => $teacher->getEmail() ?? '',
            'current_password'     => '',
            'new_password'         => '',
            'new_password_confirm' => '',
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_profile', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'first_name'           => trim($request->request->getString('first_name')),
                'last_name'            => trim($request->request->getString('last_name')),
                'email'                => trim($request->request->getString('email')),
                'current_password'     => $request->request->getString('current_password'),
                'new_password'         => $request->request->getString('new_password'),
                'new_password_confirm' => $request->request->getString('new_password_confirm'),
            ];

            if ($values['first_name'] === '') {
                $errors['first_name'] = $this->t('profile.error.first_name_required');
            }

            if ($values['last_name'] === '') {
                $errors['last_name'] = $this->t('profile.error.last_name_required');
            }

            if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = $this->t('profile.error.email_invalid');
            } elseif ($values['email'] !== '' && $values['email'] !== $teacher->getEmail()
                && $this->teachers->isEmailTakenByAnother($values['email'], $teacher)
            ) {
                $errors['email'] = $this->t('profile.error.email_taken');
            }

            if (!$teacher->isExternal() && $values['new_password'] !== '') {
                if ($values['current_password'] === '') {
                    $errors['current_password'] = $this->t('profile.error.current_password_required');
                } elseif (!$this->hasher->isPasswordValid($teacher, $values['current_password'])) {
                    $errors['current_password'] = $this->t('profile.error.current_password_invalid');
                }

                if (($policyViolation = $this->passwordPolicy->firstViolationKey($values['new_password'])) !== null) {
                    $errors['new_password'] = $this->translator->trans(
                        $policyViolation,
                        ['%min%' => PasswordPolicy::MIN_LENGTH],
                        'messages',
                    );
                }

                if ($values['new_password'] !== $values['new_password_confirm']) {
                    $errors['new_password_confirm'] = $this->t('profile.error.password_mismatch');
                }
            }

            if (empty($errors)) {
                $newEmail = $values['email'] !== '' ? $values['email'] : null;

                $teacher->setName(new PersonName($values['first_name'], $values['last_name']));

                if (!$teacher->isAdmin() && $newEmail !== null && $newEmail !== $teacher->getEmail()) {
                    $token = bin2hex(random_bytes(32));
                    $teacher
                        ->setPendingEmail($newEmail)
                        ->setEmailVerificationToken($token)
                        ->setEmailVerificationTokenExpiresAt($this->clock->now()->modify('+24 hours'));
                    $this->em->flush();
                    $this->profileMailer->sendEmailVerification($teacher, $newEmail, $token);
                    $this->addFlash('success', $this->t('profile.flash.email_verification_sent'));
                } else {
                    $teacher->setEmail($newEmail);
                    $this->em->flush();
                    $this->addFlash('success', $this->t('profile.flash.saved'));
                }

                if (!$teacher->isExternal() && $values['new_password'] !== '') {
                    $teacher->setPassword($this->hasher->hashPassword($teacher, $values['new_password']));
                    $this->em->flush();
                }

                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('profile/edit.html.twig', [
            'teacher' => $teacher,
            'errors'  => $errors,
            'values'  => $values,
        ]);
    }

    #[Route('/ajustes', name: 'app_profile_settings')]
    public function settings(): Response
    {
        return $this->render('profile/settings.html.twig');
    }

    private function translationDomain(): string
    {
        return 'messages';
    }
}
