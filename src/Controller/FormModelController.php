<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\FormModel;
use App\Entity\FormUpdatePolicy;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\FolderRepository;
use App\Repository\FormModelRepository;
use App\Repository\SpecificProfileRepository;
use App\Security\Voter\QualityVoter;
use App\Service\Form\FormDataNormalizer;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormDefinitionNormalizer;
use App\Service\Form\FormException;
use App\Service\Form\FormModelService;
use App\Service\Form\FormResolver;
use App\Service\Form\FormUpdateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The document models ("modelos"): the list, creating one, the editor (which saves the draft as it is changed), the preview and
 * sending a draft to review. Whoever manages quality (QualityVoter::MANAGE) creates and edits models; approving a revision is
 * the review of the folder it lives in, with the usual screens.
 */
#[Route('/modelos')]
class FormModelController extends AbstractController
{
    public function __construct(
        private readonly FormModelRepository $models,
        private readonly FolderRepository $folders,
        private readonly SpecificProfileRepository $profiles,
        private readonly FormModelService $service,
        private readonly FormUpdateService $updates,
        private readonly \App\Service\Form\FormSourceChoices $sourceChoices,
        private readonly FormDefinitionNormalizer $definitions,
        private readonly FormDataNormalizer $data,
        private readonly FormResolver $resolver,
        private readonly \App\Service\Form\FormListOptions $lists,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('', name: 'app_form_models', methods: ['GET'])]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);

        $folders = [];
        foreach ($this->folders->findAllByCentre($centre) as $folder) {
            $folders[] = ['id' => $folder->getId()->toRfc4122(), 'label' => $folder->getDocumentSection()->getName() . ' › ' . $folder->getName()];
        }
        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $this->render('form/models.html.twig', [
            'centre'  => $centre,
            'models'  => $this->models->findByCentre($centre),
            'folders' => $folders,
        ]);
    }

    #[Route('/nuevo', name: 'app_form_model_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        if (!$this->isCsrfTokenValid('form_model_create', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name   = trim($request->request->getString('name'));
        $folder = $this->folders->findById($request->request->getString('folder'));
        if ($name === '' || $folder === null || $folder->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122()) {
            $this->addFlash('error', $this->translator->trans('model.error.create', [], 'forms'));

            return $this->redirectToRoute('app_form_models');
        }

        $model = $this->service->create($folder, mb_substr($name, 0, 255), $request->request->getString('code'));

        return $this->redirectToRoute('app_form_model_edit', ['id' => $model->getId()->toRfc4122()]);
    }

    #[Route('/{id}', name: 'app_form_model_edit', methods: ['GET'])]
    public function edit(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $model = $this->requireModel($id, $centre);

        return $this->render('form/model_editor.html.twig', [
            'centre'   => $centre,
            'model'    => $model,
            'document' => $model->getDocument(),
            'impact'   => $this->updates->impact($model),
            'indicatorSources' => $this->sourceChoices->forCentre($centre, $model),
            'hasRevisions' => $model->getDocument()->getRevisions()->count() > 0,
            'problems' => $this->translateProblems($this->service->problems($this->definitions->normalize($model->getDraftDefinition()), $centre)),
            'editor'   => [
                'definition' => $model->getDraftDefinition(),
                'version'    => $model->getDraftVersion(),
                'code'       => $model->getCode(),
                'saveUrl'    => $this->generateUrl('app_form_model_save', ['id' => $id]),
                'csrf'       => $this->csrf->getToken('form_model_' . $id)->getValue(),
                'profiles'   => array_map(static fn (SpecificProfile $p): array => ['id' => $p->getId()->toRfc4122(), 'name' => $p->getName()], $this->profiles->findByCentre($centre)),
                // The lists of Responsabilidades a choice can take its options from, with what each offers now.
                'lists'      => $this->lists->pickable($centre),
            ],
        ]);
    }

    /**
     * Saves the draft: JSON { version, code, definition }. Answers { version, problems } — a draft with problems is saved all the
     * same, the problems are what stops it from being sent to review.
     */
    #[Route('/{id}/borrador', name: 'app_form_model_save', methods: ['POST'])]
    public function save(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): JsonResponse
    {
        $model = $this->requireModel($id, $centre);
        if (!$this->isCsrfTokenValid('form_model_' . $id, $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_array($payload['definition'] ?? null)) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var array<string, mixed> $definition */
            $definition = $payload['definition'];
            $model->setCode(is_string($payload['code'] ?? null) ? mb_substr($payload['code'], 0, 40) : null);
            $this->service->saveDraft($model, $definition, FormDefinition::int($payload['version'] ?? 0), $this->teacher());
        } catch (FormException $e) {
            return new JsonResponse(['error' => 'stale', 'message' => $this->translator->trans('model.' . $e->getMessage(), [], 'forms'), 'version' => $model->getDraftVersion()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'version'    => $model->getDraftVersion(),
            'definition' => $model->getDraftDefinition(),
            'problems'   => $this->translateProblems($this->service->problems($model->getDraftDefinition(), $centre)),
        ]);
    }

    #[Route('/{id}/vista', name: 'app_form_model_preview', methods: ['GET'])]
    public function preview(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $model      = $this->requireModel($id, $centre);
        $definition = $this->definitions->normalize($model->getDraftDefinition());

        return $this->render('form/preview.html.twig', [
            'centre'   => $centre,
            'title'    => $model->getDocument()->getName(),
            'code'     => $model->getCode(),
            'sections' => $this->resolver->resolve($definition, $this->data->sample($definition), ['centre' => $centre->getName(), 'activity' => $model->getDocument()->getName(), 'slot' => '', 'year' => '', 'date' => '']),
            'backUrl'  => $this->generateUrl('app_form_model_edit', ['id' => $id]),
            'blank'    => true,
        ]);
    }

    #[Route('/{id}/enviar', name: 'app_form_model_send', methods: ['POST'])]
    public function send(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $model = $this->requireModel($id, $centre);
        if (!$this->isCsrfTokenValid('form_model_send_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $description = trim($request->request->getString('description'));
            $mandatory   = $request->request->getString('policy') === FormUpdatePolicy::Mandatory->value;
            $deadline    = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->request->getString('deadline'));
            $revision    = $this->service->sendToReview(
                $model,
                $this->teacher(),
                $description === '' ? null : mb_substr($description, 0, 2000),
                $mandatory ? FormUpdatePolicy::Mandatory : FormUpdatePolicy::Optional,
                $deadline === false ? null : $deadline,
            );
        } catch (FormException $e) {
            $this->addFlash('error', $this->translator->trans('model.' . $e->getMessage(), [], 'forms'));

            return $this->redirectToRoute('app_form_model_edit', ['id' => $id]);
        }

        $this->addFlash('success', $this->translator->trans($revision->isPendingReview() ? 'model.flash.sent_pending' : 'model.flash.sent_active', ['%version%' => $revision->getVersion()], 'forms'));

        return $this->redirectToRoute('app_form_models');
    }

    private function requireModel(string $id, EducationalCentre $centre): FormModel
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $model = $this->models->findByIdAndCentre($id, $centre);
        if ($model === null) {
            throw $this->createNotFoundException();
        }

        return $model;
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param list<array{code: string, params: array<string, string>}> $problems
     *
     * @return list<string>
     */
    private function translateProblems(array $problems): array
    {
        return array_map(fn (array $p): string => $this->translator->trans('model.problem.' . $p['code'], $p['params'], 'forms'), $problems);
    }
}
