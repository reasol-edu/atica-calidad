<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\DocumentSectionJsonExporter;
use App\Service\DocumentSectionJsonImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class DocumentTreeController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly DocumentSectionJsonExporter $exporter,
        private readonly DocumentSectionJsonImporter $importer,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/arbol-documental', name: 'app_document_tree')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $canEdit      = $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $requestedTab = $request->query->getString('tab');
        $tab          = $canEdit && $requestedTab === 'edit' ? 'edit' : 'view';

        return $this->render('document_tree/index.html.twig', [
            'centre'  => $centre,
            'canEdit' => $canEdit,
            'tab'     => $tab,
        ]);
    }

    #[Route('/arbol-documental/exportar', name: 'app_document_tree_export')]
    public function export(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);

        $json = json_encode($this->exporter->export($centre), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, 200, [
            'Content-Disposition' => 'attachment; filename="arbol-documental-' . $centre->getCode() . '.json"',
        ], true);
    }

    #[Route('/arbol-documental/importar', name: 'app_document_tree_import')]
    public function import(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);

        if (!$request->isMethod('POST')) {
            return $this->render('document_tree/import.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_document_sections', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('document_section.import.error.no_file'));

            return $this->render('document_tree/import.html.twig', ['centre' => $centre]);
        }

        try {
            $data = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Root must be an object.');
            }
            $stats = $this->importer->import($data, $centre);
        } catch (\Throwable) {
            $this->addFlash('error', $this->t('document_section.import.error.invalid_file'));

            return $this->render('document_tree/import.html.twig', ['centre' => $centre]);
        }

        $this->addFlash('success', $this->translator->trans('document_section.import.flash.summary', [
            '%sections%'         => $stats['sections'],
            '%skippedProfiles%'  => $stats['skippedProfiles'],
        ], 'admin'));

        return $this->redirectToRoute('app_document_tree', ['tab' => 'edit']);
    }
}
