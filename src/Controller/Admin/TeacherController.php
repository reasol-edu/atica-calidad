<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/docentes')]
#[IsGranted('ROLE_ADMIN')]
class TeacherController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeacherRepository $teachers,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_admin_teachers_index')]
    public function index(): Response
    {
        return $this->render('admin/teacher/index.html.twig');
    }

    #[Route('/nuevo', name: 'app_admin_teachers_new')]
    public function new(Request $request): Response
    {
        $errors = [];
        $values = ['first_name' => '', 'last_name' => '', 'username' => '', 'email' => '', 'password' => ''];
        $flags  = ['admin' => false, 'active' => true, 'external' => false, 'force_password_change' => false];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_teacher', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'first_name' => trim($request->request->getString('first_name')),
                'last_name'  => trim($request->request->getString('last_name')),
                'username'   => trim($request->request->getString('username')),
                'email'      => trim($request->request->getString('email')),
                'password'   => $request->request->getString('password'),
            ];
            $flags = [
                'admin'    => $request->request->getString('admin') === 'yes',
                'active'   => $request->request->getString('active') === 'yes',
                'external' => $request->request->getString('auth_method') === 'external',
            ];
            $flags['force_password_change'] = !$flags['external']
                && $request->request->getString('force_password_change') === 'yes';

            $errors = $this->validateTeacher($values, !$flags['external']);

            if (empty($errors['username']) && $this->teachers->findByUsername($values['username']) !== null) {
                $errors['username'] = $this->t('teacher.error.username_duplicate');
            }

            if (empty($errors)) {
                $teacher = new Teacher(new PersonName($values['first_name'], $values['last_name']));
                $teacher->setUsername($values['username'])
                    ->setEmail($values['email'] !== '' ? $values['email'] : null)
                    ->setAdmin($flags['admin'])
                    ->setActive($flags['active'])
                    ->setExternal($flags['external'])
                    ->setForcePasswordChange($flags['force_password_change']);

                if (!$flags['external']) {
                    $teacher->setPassword($this->hasher->hashPassword($teacher, $values['password']));
                }

                $this->em->persist($teacher);
                $this->em->flush();

                $this->addFlash('success', $this->t('teacher.flash.created'));

                return $this->redirectToRoute('app_admin_teachers_index');
            }
        }

        return $this->render('admin/teacher/new.html.twig', [
            'errors' => $errors,
            'values' => $values,
            'flags'  => $flags,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_teachers_edit')]
    public function edit(string $id, Request $request): Response
    {
        $teacher = $this->teachers->findById($id);
        if ($teacher === null) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();
        $isCurrentUser = $currentUser instanceof Teacher && $currentUser->getId()->toRfc4122() === $id;

        $errors = [];
        $values = [
            'first_name' => $teacher->getName()->getFirstName(),
            'last_name'  => $teacher->getName()->getLastName(),
            'username'   => $teacher->getUsername(),
            'email'      => $teacher->getEmail() ?? '',
            'password'   => '',
        ];
        $flags = [
            'admin'    => $teacher->isAdmin(),
            'active'   => $teacher->isActive(),
            'external' => $teacher->isExternal(),
            'force_password_change' => $teacher->isForcePasswordChange(),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_teacher_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'first_name' => trim($request->request->getString('first_name')),
                'last_name'  => trim($request->request->getString('last_name')),
                'username'   => trim($request->request->getString('username')),
                'email'      => trim($request->request->getString('email')),
                'password'   => $request->request->getString('password'),
            ];
            $flags = [
                'admin'    => $request->request->getString('admin') === 'yes',
                'active'   => $request->request->getString('active') === 'yes',
                'external' => $request->request->getString('auth_method') === 'external',
            ];
            $flags['force_password_change'] = !$flags['external']
                && $request->request->getString('force_password_change') === 'yes';

            if ($isCurrentUser) {
                $selfProtectionErrors = [];

                if (!$flags['admin']) {
                    $selfProtectionErrors[] = $this->t('teacher.flash.demote_self_error');
                }

                if (!$flags['active']) {
                    $selfProtectionErrors[] = $this->t('teacher.flash.deactivate_self_error');
                }

                if ($selfProtectionErrors !== []) {
                    foreach ($selfProtectionErrors as $message) {
                        $this->addFlash('error', $message);
                    }

                    return $this->redirectToRoute('app_admin_teachers_edit', ['id' => $id]);
                }
            }

            $errors = $this->validateTeacher($values, false);

            $existing = $this->teachers->findByUsername($values['username']);
            if (empty($errors['username']) && $existing !== null
                && $existing->getId()->toRfc4122() !== $id) {
                $errors['username'] = $this->t('teacher.error.username_duplicate');
            }

            if (empty($errors)) {
                $teacher->setName(new PersonName($values['first_name'], $values['last_name']))
                    ->setUsername($values['username'])
                    ->setEmail($values['email'] !== '' ? $values['email'] : null)
                    ->setAdmin($flags['admin'])
                    ->setActive($flags['active'])
                    ->setExternal($flags['external'])
                    ->setForcePasswordChange($flags['force_password_change']);

                if ($values['password'] !== '') {
                    $teacher->setPassword($this->hasher->hashPassword($teacher, $values['password']));
                }

                $this->em->flush();

                $this->addFlash('success', $this->t('teacher.flash.saved'));

                return $this->redirectToRoute('app_admin_teachers_edit', ['id' => $id]);
            }
        }

        return $this->render('admin/teacher/edit.html.twig', [
            'teacher'         => $teacher,
            'errors'          => $errors,
            'values'          => $values,
            'flags'           => $flags,
            'is_current_user' => $isCurrentUser,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_admin_teachers_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_teacher_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $teacher = $this->teachers->findById($id);
        if ($teacher === null) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof Teacher && $currentUser->getId()->toRfc4122() === $id) {
            $this->addFlash('error', $this->t('teacher.flash.delete_self_error'));

            return $this->redirectToRoute('app_admin_teachers_index');
        }

        try {
            $this->em->remove($teacher);
            $this->em->flush();

            $this->addFlash('success', $this->t('teacher.flash.deleted'));
        } catch (\Exception) {
            $this->addFlash('error', $this->t('teacher.flash.delete_error'));
        }

        return $this->redirectToRoute('app_admin_teachers_index');
    }

    /**
     * @param  array<string, string> $values
     * @return array<string, string>
     */
    private function validateTeacher(array $values, bool $passwordRequired): array
    {
        $errors = [];

        if ($values['first_name'] === '') {
            $errors['first_name'] = $this->t('teacher.error.first_name_required');
        }

        if ($values['last_name'] === '') {
            $errors['last_name'] = $this->t('teacher.error.last_name_required');
        }

        if ($values['username'] === '') {
            $errors['username'] = $this->t('teacher.error.username_required');
        }

        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $this->t('teacher.error.email_invalid');
        }

        if ($passwordRequired && $values['password'] === '') {
            $errors['password'] = $this->t('teacher.error.password_required');
        }

        return $errors;
    }
}
