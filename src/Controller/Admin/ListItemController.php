<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ListItemJsonExporter;
use App\Service\ListItemJsonImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/responsabilidades/listas')]
class ListItemController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly ListItemJsonExporter $exporter,
        private readonly ListItemJsonImporter $importer,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_responsibilities_lists')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/list_item/index.html.twig', [
            'centre' => $centre,
        ]);
    }

    #[Route('/exportar', name: 'app_responsibilities_lists_export')]
    public function export(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);
        $json   = json_encode($this->exporter->export($centre), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, 200, [
            'Content-Disposition' => 'attachment; filename="listas-' . $centre->getCode() . '.json"',
        ], true);
    }

    #[Route('/importar', name: 'app_responsibilities_lists_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/list_item/import.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_list_items', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('responsibilities.lists.import.error.no_file'));

            return $this->render('admin/list_item/import.html.twig', ['centre' => $centre]);
        }

        try {
            $data = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Root must be an object.');
            }
            $stats = $this->importer->import($data, $centre);
        } catch (\DomainException) {
            $this->addFlash('error', $this->t('responsibilities.lists.import.error.in_use'));

            return $this->render('admin/list_item/import.html.twig', ['centre' => $centre]);
        } catch (\Throwable) {
            $this->addFlash('error', $this->t('responsibilities.lists.import.error.invalid_file'));

            return $this->render('admin/list_item/import.html.twig', ['centre' => $centre]);
        }

        $this->addFlash('success', $this->translator->trans('responsibilities.lists.import.flash.summary', [
            '%items%' => $stats['items'],
            '%tags%'  => $stats['tags'],
        ], 'admin'));

        return $this->redirectToRoute('app_responsibilities_lists', ['centreId' => $centreId]);
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
