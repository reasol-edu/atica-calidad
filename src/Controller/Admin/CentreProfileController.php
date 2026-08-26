<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/perfiles')]
class CentreProfileController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly TeacherRepository $teachers,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_centre_profiles_index')]
    public function index(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        /** @var Teacher[] $selectedQualityManagers */
        $selectedQualityManagers = $centre->getQualityManagers()->toArray();
        /** @var Teacher[] $selectedInternalAuditors */
        $selectedInternalAuditors = $centre->getInternalAuditors()->toArray();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_centre_profiles_' . $centreId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $submittedQualityManagerIds = $this->submittedIds($request, 'quality_managers');
            $submittedInternalAuditorIds = $this->submittedIds($request, 'internal_auditors');

            foreach ($centre->getQualityManagers()->toArray() as $teacher) {
                $centre->removeQualityManager($teacher);
            }
            foreach ($submittedQualityManagerIds as $teacherId) {
                $teacher = $this->teachers->findById($teacherId);
                if ($teacher !== null) {
                    $centre->addQualityManager($teacher);
                }
            }

            foreach ($centre->getInternalAuditors()->toArray() as $teacher) {
                $centre->removeInternalAuditor($teacher);
            }
            foreach ($submittedInternalAuditorIds as $teacherId) {
                $teacher = $this->teachers->findById($teacherId);
                if ($teacher !== null) {
                    $centre->addInternalAuditor($teacher);
                }
            }

            $this->em->flush();

            $this->addFlash('success', $this->t('centre_profiles.flash.saved'));

            return $this->redirectToRoute('app_centre_profiles_index', ['centreId' => $centre->getId()]);
        }

        return $this->render('admin/centre_profile/index.html.twig', [
            'centre'                   => $centre,
            'selectedQualityManagers'  => $selectedQualityManagers,
            'selectedInternalAuditors' => $selectedInternalAuditors,
        ]);
    }

    /**
     * @return list<string>
     */
    private function submittedIds(Request $request, string $field): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $v): string => \is_string($v) ? $v : '',
                $request->request->all($field)
            ),
            static fn (string $v): bool => $v !== ''
        ));
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }
}
