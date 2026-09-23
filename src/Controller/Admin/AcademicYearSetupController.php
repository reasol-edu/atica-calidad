<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\AcademicYearRepository;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AcademicYearSetupChecklist;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Preparar el nuevo curso": a checklist (AcademicYearSetupChecklist) that walks a centre admin
 * through getting a new academic year ready, with the steps' own actions — create the year,
 * activate it, copy the teachers of a previous year — done right here, and links to the pages that
 * already handle the rest (importing teachers or non-working days, profile assignments).
 */
#[Route('/centro/{centreId}/cursos/preparar')]
class AcademicYearSetupController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly AcademicYearRepository $years,
        private readonly AcademicYearSetupChecklist $checklist,
        private readonly ActivityLogger $activityLogger,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_centre_year_setup')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);
        $target = $this->checklist->targetYear($centre);

        return $this->render('admin/academic_year/setup.html.twig', [
            'centre'        => $centre,
            'target'        => $target,
            'steps'         => $this->checklist->steps($centre, $target),
            'suggestedName' => $this->checklist->suggestedName($centre),
            'copySources'   => $this->checklist->copySources($centre, $target),
            'stale'         => $target === null ? [] : $this->checklist->staleAssignments($centre, $target),
        ]);
    }

    #[Route('/crear', name: 'app_centre_year_setup_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkToken($request, 'year_setup_create_' . $centreId);

        $name = trim($request->request->getString('name'));
        if ($name === '') {
            $this->addFlash('error', $this->t('year.flash.name_required'));
        } elseif (array_filter($this->years->findByCentreOrderedByName($centre), static fn (AcademicYear $y): bool => $y->getName() === $name) !== []) {
            $this->addFlash('error', $this->t('year_setup.flash.name_taken'));
        } else {
            $this->em->persist((new AcademicYear())->setName($name)->setEducationalCentre($centre));
            $this->em->flush();
            $this->addFlash('success', $this->t('year.flash.added'));
        }

        return $this->redirectToRoute('app_centre_year_setup', ['centreId' => $centreId]);
    }

    // Ahead of AcademicYearController's "/cursos/{yearId}/activar", which would otherwise take "preparar" for a year id.
    #[Route('/activar', name: 'app_centre_year_setup_activate', methods: ['POST'], priority: 1)]
    public function activate(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkToken($request, 'year_setup_activate_' . $centreId);

        $target = $this->checklist->targetYear($centre);
        if ($target === null) {
            throw $this->createNotFoundException();
        }

        $centre->setActiveAcademicYear($target);
        $this->em->flush();
        $this->addFlash('success', $this->t('year.flash.activated'));

        return $this->redirectToRoute('app_centre_year_setup', ['centreId' => $centreId]);
    }

    /** Adds every teacher of an earlier year to the one being prepared (who's already in it is left as is). */
    #[Route('/copiar-docentes', name: 'app_centre_year_setup_copy_teachers', methods: ['POST'])]
    public function copyTeachers(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkToken($request, 'year_setup_copy_teachers_' . $centreId);

        $target = $this->checklist->targetYear($centre);
        $source = $this->years->findByCentreAndId($centre, $request->request->getString('source'));
        if ($target === null || $source === null || $source === $target) {
            throw $this->createNotFoundException();
        }

        $added = 0;
        foreach ($source->getTeachers() as $teacher) {
            if (!$target->getTeachers()->contains($teacher)) {
                $target->addTeacher($teacher);
                ++$added;
            }
        }
        $this->em->flush();

        $this->activityLogger->record('academic_year.copy_teachers', ['from' => $source->getName(), 'to' => $target->getName(), 'added' => $added], $centre);
        $this->addFlash('success', $this->translator->trans('year_setup.flash.teachers_copied', ['%count%' => $added, '%year%' => $source->getName()], 'admin'));

        return $this->redirectToRoute('app_centre_year_setup', ['centreId' => $centreId]);
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
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
