<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityBulkEditPlan;
use App\Repository\ActivityRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityBulkEditor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Edición en bloque": one change applied to several activities at once — move to another category,
 * hide or show, general deadline, required or optional. Three steps: pick the activities and the
 * change, a preview of what each would go from and to, and the confirmation that applies it.
 */
#[Route('/actividades/edicion-en-bloque')]
class ActivityBulkEditController extends AbstractController
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivityBulkEditor $editor,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_activity_bulk', methods: ['GET'])]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->requireEditor($centre);

        $groups = [];
        foreach ($this->activities->findAllByCentre($centre, true) as $activity) {
            $path = $this->editor->path($activity->getCategory());
            $groups[$path][] = $activity;
        }

        $choices = [];
        foreach ($this->editor->categoryChoices($centre) as $category) {
            $choices[$category->getId()->toRfc4122()] = $this->editor->path($category);
        }

        return $this->render('activity/bulk.html.twig', [
            'groups'     => $groups,
            'categories' => $choices,
        ]);
    }

    #[Route('/vista-previa', name: 'app_activity_bulk_preview', methods: ['POST'])]
    public function preview(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->requireEditor($centre);
        $this->requireCsrf($request);

        $plan = $this->planFrom($request, $centre);
        if (\is_string($plan)) {
            $this->addFlash('error', $this->translator->trans($plan, [], 'activity_content'));

            return $this->redirectToRoute('app_activity_bulk');
        }

        return $this->render('activity/bulk_preview.html.twig', ['plan' => $plan]);
    }

    #[Route('/aplicar', name: 'app_activity_bulk_apply', methods: ['POST'])]
    public function apply(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->requireEditor($centre);
        $this->requireCsrf($request);

        // Planned again from what the preview carried: the selection is never trusted as it was shown.
        $plan = $this->planFrom($request, $centre);
        if (\is_string($plan)) {
            $this->addFlash('error', $this->translator->trans($plan, [], 'activity_content'));

            return $this->redirectToRoute('app_activity_bulk');
        }

        $user    = $this->getUser();
        $changed = $this->editor->apply($centre, $plan, $user instanceof Teacher ? $user : null);
        $this->addFlash('success', $this->translator->trans('bulk.flash.applied', ['%count%' => $changed], 'activity_content'));

        return $this->redirectToRoute('app_activities', ['tab' => 'view']);
    }

    private function planFrom(Request $request, EducationalCentre $centre): ActivityBulkEditPlan|string
    {
        $ids = array_values(array_filter($request->request->all('ids'), '\is_string'));

        return $this->editor->plan($centre, $request->request->getString('action'), $request->request->all(), $ids);
    }

    private function requireEditor(EducationalCentre $centre): void
    {
        if (!$this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('activity_bulk_edit', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
