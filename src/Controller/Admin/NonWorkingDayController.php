<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Repository\EducationalCentreRepository;
use App\Repository\NonWorkingDayRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\NonWorkingDayCsvImporter;
use App\Service\NonWorkingDayIcsImporter;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/dias-no-lectivos')]
class NonWorkingDayController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly NonWorkingDayRepository $nonWorkingDays,
        private readonly TenantContext $tenantContext,
        private readonly NonWorkingDayIcsImporter $icsImporter,
        private readonly NonWorkingDayCsvImporter $csvImporter,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_centre_non_working_days_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/non_working_day/index.html.twig', [
            'centre' => $centre,
            'year'   => $year,
            'days'   => $this->nonWorkingDays->findByAcademicYearOrdered($year),
        ]);
    }

    #[Route('/nuevo', name: 'app_centre_non_working_days_add', methods: ['POST'])]
    public function add(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('add_non_working_day_' . $centreId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $date        = $this->parseDate($request->request->getString('date'));
        $description = trim($request->request->getString('description'));

        if ($date === null) {
            $this->addFlash('error', $this->t('non_working_day.error.date_required'));
        } elseif ($this->nonWorkingDays->findByAcademicYearAndDate($year, $date) !== null) {
            $this->addFlash('error', $this->t('non_working_day.error.duplicate'));
        } else {
            $nonWorkingDay = (new NonWorkingDay())
                ->setDate($date)
                ->setDescription($description !== '' ? $description : null)
                ->setAcademicYear($year);

            $this->em->persist($nonWorkingDay);
            $this->em->flush();

            $this->addFlash('success', $this->t('non_working_day.flash.added'));
        }

        return $this->redirectToRoute('app_centre_non_working_days_index', ['centreId' => $centreId]);
    }

    #[Route('/{dayId}/editar', name: 'app_centre_non_working_days_edit')]
    public function edit(string $centreId, string $dayId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $nonWorkingDay = $this->nonWorkingDays->findByAcademicYearAndId($year, $dayId);
        if ($nonWorkingDay === null) {
            throw $this->createNotFoundException();
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_non_working_day_' . $dayId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $date        = $this->parseDate($request->request->getString('date'));
            $description = trim($request->request->getString('description'));

            $duplicate = $date !== null
                && $date != $nonWorkingDay->getDate()
                && $this->nonWorkingDays->findByAcademicYearAndDate($year, $date) !== null;

            if ($date === null) {
                $errors['date'] = $this->t('non_working_day.error.date_required');
            } elseif ($duplicate) {
                $errors['date'] = $this->t('non_working_day.error.duplicate');
            } else {
                $nonWorkingDay
                    ->setDate($date)
                    ->setDescription($description !== '' ? $description : null);

                $this->em->flush();

                $this->addFlash('success', $this->t('non_working_day.flash.saved'));

                return $this->redirectToRoute('app_centre_non_working_days_index', ['centreId' => $centreId]);
            }
        }

        return $this->render('admin/non_working_day/edit.html.twig', [
            'centre' => $centre,
            'day'    => $nonWorkingDay,
            'errors' => $errors,
        ]);
    }

    #[Route('/{dayId}/eliminar', name: 'app_centre_non_working_days_delete', methods: ['POST'])]
    public function delete(string $centreId, string $dayId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete_non_working_day_' . $dayId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $nonWorkingDay = $this->nonWorkingDays->findByAcademicYearAndId($year, $dayId);
        if ($nonWorkingDay === null) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($nonWorkingDay);
        $this->em->flush();

        $this->addFlash('success', $this->t('non_working_day.flash.deleted'));

        return $this->redirectToRoute('app_centre_non_working_days_index', ['centreId' => $centreId]);
    }

    #[Route('/importar', name: 'app_centre_non_working_days_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        if (!$request->isMethod('POST')) {
            return $this->render('admin/non_working_day/import.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_non_working_days', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('ics');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('non_working_day.import.error.no_file'));

            return $this->render('admin/non_working_day/import.html.twig', ['centre' => $centre]);
        }

        try {
            $stats = $this->icsImporter->import($file->getPathname(), $year);
        } catch (\Throwable) {
            $this->addFlash('error', $this->t('non_working_day.import.error.invalid_file'));

            return $this->render('admin/non_working_day/import.html.twig', ['centre' => $centre]);
        }

        $this->addFlash('success', $this->translator->trans('non_working_day.import.flash.summary', [
            '%new%'      => $stats['new'],
            '%existing%' => $stats['existing'],
        ], 'admin'));

        return $this->redirectToRoute('app_centre_non_working_days_index', ['centreId' => $centreId]);
    }

    #[Route('/importar-seneca', name: 'app_centre_non_working_days_import_seneca', methods: ['POST'])]
    public function importSeneca(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('import_seneca_non_working_days', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('csv');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('non_working_day.import.error.no_file'));

            return $this->redirectToRoute('app_centre_non_working_days_import', ['centreId' => $centreId]);
        }

        try {
            $stats = $this->csvImporter->import($file->getPathname(), $year);
        } catch (\Throwable) {
            $this->addFlash('error', $this->t('non_working_day.import.seneca.error.invalid_file'));

            return $this->redirectToRoute('app_centre_non_working_days_import', ['centreId' => $centreId]);
        }

        $this->addFlash('success', $this->translator->trans('non_working_day.import.seneca.flash.summary', [
            '%new%'      => $stats['new'],
            '%existing%' => $stats['existing'],
            '%skipped%'  => $stats['skipped'],
        ], 'admin'));

        return $this->redirectToRoute('app_centre_non_working_days_index', ['centreId' => $centreId]);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false ? $date : null;
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }
}
