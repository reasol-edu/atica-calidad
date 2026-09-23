<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Repository\ActivityRepository;
use App\Repository\DocumentRepository;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogger;
use App\Service\TrashService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Papelera": the centre's deleted documents and activities (TrashService), to restore or delete
 * for good before they're purged on their own. For whoever manages the centre's responsibilities
 * (admins, quality managers): they answer for the whole quality system, including what others
 * deleted.
 */
#[Route('/centro/{centreId}/papelera')]
class TrashController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly DocumentRepository $documents,
        private readonly ActivityRepository $activities,
        private readonly TrashService $trash,
        private readonly ActivityLogger $activityLogger,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_trash_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('trash/index.html.twig', [
            'centre'        => $centre,
            'documents'     => $this->documents->findTrashedByCentre($centre),
            'activities'    => $this->activities->findTrashedByCentre($centre),
            'retentionDays' => $this->trash->retentionDays($centre),
        ]);
    }

    #[Route('/documentos/{id}/{action}', name: 'app_trash_document', requirements: ['action' => 'recuperar|eliminar'], methods: ['POST'])]
    public function document(string $centreId, string $id, string $action, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkToken($request, 'trash_document_' . $id);

        $document = $this->documents->findTrashedByIdAndCentre($id, $centre);
        if ($document === null) {
            throw $this->createNotFoundException();
        }
        $data = ['folder' => $document->getFolder()->getName(), 'document' => $document->getName()];

        if ($action === 'recuperar') {
            $this->trash->restoreDocument($document);
            $this->activityLogger->record('trash.restore', $data, $centre);
            $this->addFlash('success', $this->t('trash.flash.restored'));
        } else {
            $this->trash->purgeDocument($document);
            $this->activityLogger->record('trash.purge', $data, $centre);
            $this->addFlash('success', $this->t('trash.flash.purged'));
        }

        return $this->redirectToRoute('app_trash_index', ['centreId' => $centreId]);
    }

    #[Route('/actividades/{id}/{action}', name: 'app_trash_activity', requirements: ['action' => 'recuperar|eliminar'], methods: ['POST'])]
    public function activity(string $centreId, string $id, string $action, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkToken($request, 'trash_activity_' . $id);

        $activity = $this->activities->findTrashedByIdAndCentre($id, $centre);
        if ($activity === null) {
            throw $this->createNotFoundException();
        }
        $data = ['activity' => $activity->getTitle()];

        if ($action === 'recuperar') {
            $relinked = $this->trash->restoreActivity($activity);
            $this->activityLogger->record('trash.restore', $data, $centre);
            $this->addFlash('success', $this->t($relinked ? 'trash.flash.restored' : 'trash.flash.restored_without_folder'));
        } else {
            $this->trash->purgeActivity($activity);
            $this->activityLogger->record('trash.purge', $data, $centre);
            $this->addFlash('success', $this->t('trash.flash.purged'));
        }

        return $this->redirectToRoute('app_trash_index', ['centreId' => $centreId]);
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);

        return $centre;
    }
}
