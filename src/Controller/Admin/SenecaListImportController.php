<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Model\SenecaListImportKind;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\SenecaListCsvParser;
use App\Service\SenecaListImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Imports the "Alumnado › Relación de unidades" (groups) and "Personal › Personal del centro ›
 * Materias y grupos" (subjects) Séneca CSV exports into a Responsabilidades › Listas root — see
 * SenecaListCsvParser for the CSV shapes and SenecaListImporter for how the import is diffed
 * against, and merged into, what already exists. Unlike ListItemController's JSON
 * export/import (a full-tree backup/restore), this always previews the additions/removals it
 * would make and only touches the database once the user confirms them.
 */
#[Route('/centro/{centreId}/responsabilidades/listas')]
class SenecaListImportController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly SenecaListCsvParser $parser,
        private readonly SenecaListImporter $importer,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/importar-grupos-seneca', name: 'app_responsibilities_lists_import_seneca_groups')]
    public function importGroups(string $centreId, Request $request): Response
    {
        return $this->handle($centreId, $request, SenecaListImportKind::Groups);
    }

    #[Route('/importar-materias-seneca', name: 'app_responsibilities_lists_import_seneca_subjects')]
    public function importSubjects(string $centreId, Request $request): Response
    {
        return $this->handle($centreId, $request, SenecaListImportKind::Subjects);
    }

    private function handle(string $centreId, Request $request, SenecaListImportKind $kind): Response
    {
        $centre  = $this->requireCentre($centreId);
        $default = $this->t('responsibilities.lists.import_seneca.' . $kind->value . '.default_root_name');

        if (!$request->isMethod('POST')) {
            return $this->renderForm($centre, $kind, $default);
        }

        if (!$this->isCsrfTokenValid('import_seneca_list_' . $kind->value, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $rootName = trim($request->request->getString('root_name'));
        if ($rootName === '') {
            $rootName = $default;
        }

        if ($request->request->getBoolean('confirm')) {
            return $this->applyImport($centre, $kind, $request, $rootName);
        }

        return $this->previewImport($centre, $kind, $request, $rootName);
    }

    private function previewImport(EducationalCentre $centre, SenecaListImportKind $kind, Request $request, string $rootName): Response
    {
        $file = $request->files->get('csv');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.no_file'));

            return $this->renderForm($centre, $kind, $rootName);
        }

        $content = (string) file_get_contents($file->getPathname());

        if ($this->parser->isEmpty($content)) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.empty_file'));

            return $this->renderForm($centre, $kind, $rootName);
        }

        $missing = $this->parser->missingColumn($content, $kind);
        if ($missing !== null) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.missing_column') . ' «' . $missing . '»');

            return $this->renderForm($centre, $kind, $rootName);
        }

        $desired = $this->parser->buildTree($content, $kind);
        if ($desired === []) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.no_rows'));

            return $this->renderForm($centre, $kind, $rootName);
        }

        return $this->render('admin/list_item/import_seneca.html.twig', [
            'centre'    => $centre,
            'kind'      => $kind,
            'rootName'  => $rootName,
            'plan'      => $this->importer->plan($centre, $rootName, $desired),
            'csvBase64' => base64_encode($content),
        ]);
    }

    private function applyImport(EducationalCentre $centre, SenecaListImportKind $kind, Request $request, string $rootName): Response
    {
        $csvContent = base64_decode($request->request->getString('csv_content'), true);
        if ($csvContent === false || $this->parser->isEmpty($csvContent)) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.invalid_request'));

            return $this->renderForm($centre, $kind, $rootName);
        }

        $missing = $this->parser->missingColumn($csvContent, $kind);
        if ($missing !== null) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.missing_column') . ' «' . $missing . '»');

            return $this->renderForm($centre, $kind, $rootName);
        }

        $desired = $this->parser->buildTree($csvContent, $kind);
        if ($desired === []) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.no_rows'));

            return $this->renderForm($centre, $kind, $rootName);
        }

        $deleteUnused = $request->request->getString('unused_action') !== 'deactivate';
        $counts       = $this->importer->apply($centre, $rootName, $desired, $deleteUnused);

        $this->addFlash('success', $this->translator->trans('responsibilities.lists.import_seneca.flash.summary', [
            '%added%'       => $counts['added'],
            '%deleted%'     => $counts['deleted'],
            '%deactivated%' => $counts['deactivated'],
            '%reactivated%' => $counts['reactivated'],
        ], 'admin'));

        return $this->redirectToRoute('app_responsibilities_lists', ['centreId' => $centre->getId()]);
    }

    private function renderForm(EducationalCentre $centre, SenecaListImportKind $kind, string $rootName): Response
    {
        return $this->render('admin/list_item/import_seneca.html.twig', [
            'centre'   => $centre,
            'kind'     => $kind,
            'rootName' => $rootName,
            'plan'     => null,
        ]);
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
