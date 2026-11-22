<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\FolderRepository;
use App\Repository\FormModelRepository;
use App\Security\Voter\QualityVoter;
use App\Service\Form\FormException;
use App\Service\Form\FormModelPorter;
use App\Service\Form\FormOfficeWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Models in and out as files: export (JSON for another centre, DOCX/ODT to work on elsewhere), import (JSON, DOCX, ODT) and copy
 * within the centre. Always for those who manage quality; what comes in is a new draft (see FormModelPorter).
 */
#[Route('/modelos')]
class FormModelLibraryController extends AbstractController
{
    private const int MAX_UPLOAD = 8_000_000;

    public function __construct(
        private readonly FormModelRepository $models,
        private readonly FolderRepository $folders,
        private readonly FormModelPorter $porter,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/{id}/exportar.{_format}', name: 'app_form_model_export', requirements: ['_format' => 'json|docx|odt'], methods: ['GET'])]
    public function export(string $id, string $_format, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $model    = $this->models->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException();
        $filename = (preg_replace('/[^A-Za-z0-9._-]+/', '-', $model->getDocument()->getName()) ?: 'modelo');

        if ($_format === 'json') {
            $response = new JsonResponse($this->porter->export($model), 200, [], false);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '.json"');

            return $response;
        }

        $response = new Response($this->porter->exportOffice($model, $_format, $centre->getName()));
        $response->headers->set('Content-Type', $_format === 'odt' ? 'application/vnd.oasis.opendocument.text' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '.' . $_format . '"');

        return $response;
    }

    #[Route('/{id}/duplicar', name: 'app_form_model_duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        if (!$this->isCsrfTokenValid('form_model_duplicate_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $model = $this->models->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException();
        $name  = trim($request->request->getString('name'));
        $copy  = $this->porter->duplicate(
            $model,
            $model->getDocument()->getFolder(),
            $name !== '' ? $name : $this->translator->trans('models.copy_of', ['%name%' => $model->getDocument()->getName()], 'forms'),
            $this->teacher(),
        );

        return $this->redirectToRoute('app_form_model_edit', ['id' => $copy->getId()->toRfc4122()]);
    }

    /** Creates a model from an uploaded JSON (exported by this application), DOCX or ODT, in the folder chosen. */
    #[Route('/importar', name: 'app_form_model_import', methods: ['POST'])]
    public function import(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        if (!$this->isCsrfTokenValid('form_model_import', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $folder = $this->folders->findById($request->request->getString('folder'));
        $file   = $request->files->get('file');
        if ($folder === null || $folder->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122() || !$file instanceof UploadedFile || $file->getSize() > self::MAX_UPLOAD) {
            return $this->fail();
        }

        $name      = trim($request->request->getString('name'));
        $extension = strtolower($file->getClientOriginalExtension());
        try {
            if ($extension === 'json') {
                $payload = json_decode((string) file_get_contents($file->getPathname()), true);
                $model   = $this->porter->import(\is_array($payload) ? $payload : [], $folder, $name, $this->teacher());
            } elseif (\in_array($extension, FormOfficeWriter::FORMATS, true)) {
                $model = $this->porter->importOffice($file->getPathname(), $extension, $folder, $name !== '' ? $name : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), $this->teacher());
            } else {
                return $this->fail();
            }
        } catch (FormException) {
            return $this->fail();
        }
        $this->addFlash('success', $this->translator->trans('models.imported', [], 'forms'));

        return $this->redirectToRoute('app_form_model_edit', ['id' => $model->getId()->toRfc4122()]);
    }

    private function fail(): Response
    {
        $this->addFlash('error', $this->translator->trans('models.error.import', [], 'forms'));

        return $this->redirectToRoute('app_form_models');
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        \assert($user instanceof Teacher);

        return $user;
    }
}
