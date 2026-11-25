<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Model\SenecaImportNode;
use App\Model\SenecaListImportKind;
use App\Model\SenecaSubjectsLayout;
use App\Model\SenecaTeacherMatch;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\SenecaListCsvParser;
use App\Service\SenecaListImporter;
use App\Service\SenecaTeacherMatcher;
use App\Service\UploadLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Imports the "Alumnado › Relación de unidades" (groups) and "Personal › Personal del centro ›
 * Materias y grupos" (subjects) Séneca CSV exports into a Responsabilidades › Catálogo del centro root — see
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
        private readonly SenecaTeacherMatcher $teacherMatcher,
        private readonly TranslatorInterface $translator,
        private readonly UploadLimits $uploadLimits,
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

    /**
     * The first preview reads the uploaded file; changing an option on the preview posts the same content again (hidden, as for
     * applying), without a file.
     */
    private function previewImport(EducationalCentre $centre, SenecaListImportKind $kind, Request $request, string $rootName): Response
    {
        if ($request->request->has('csv_content')) {
            $content = base64_decode($request->request->getString('csv_content'), true);
            if ($content === false || $this->parser->isEmpty($content)) {
                $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.invalid_request'));

                return $this->renderForm($centre, $kind, $rootName);
            }
        } else {
            $file = $request->files->get('csv');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $this->addFlash('error', $this->uploadLimits->fileProblem($file) ?? $this->t('responsibilities.lists.import_seneca.error.no_file'));

                return $this->renderForm($centre, $kind, $rootName);
            }

            $content = (string) file_get_contents($file->getPathname());

            if ($this->parser->isEmpty($content)) {
                $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.empty_file'));

                return $this->renderForm($centre, $kind, $rootName);
            }
        }

        $import = $this->readImport($centre, $kind, $request, $content, $rootName);
        if ($import instanceof Response) {
            return $import;
        }

        return $this->renderPreview($centre, $kind, $rootName, $content, $import);
    }

    private function applyImport(EducationalCentre $centre, SenecaListImportKind $kind, Request $request, string $rootName): Response
    {
        $csvContent = base64_decode($request->request->getString('csv_content'), true);
        if ($csvContent === false || $this->parser->isEmpty($csvContent)) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.invalid_request'));

            return $this->renderForm($centre, $kind, $rootName);
        }

        $import = $this->readImport($centre, $kind, $request, $csvContent, $rootName);
        if ($import instanceof Response) {
            return $import;
        }

        // The button stays disabled until the box is ticked, but that is only the page: the request is what decides.
        if ($import['mismatch'] && !$request->request->getBoolean('layout_ack')) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.layout_ack'));

            return $this->renderPreview($centre, $kind, $rootName, $csvContent, $import);
        }

        $deleteUnused = $request->request->getString('unused_action') !== 'deactivate';
        $counts       = $this->importer->apply($centre, $rootName, $import['desired'], $deleteUnused, $import['teachers'], !$import['mismatch']);

        $this->addFlash('success', $this->translator->trans('responsibilities.lists.import_seneca.flash.summary', [
            '%added%'       => $counts['added'],
            '%deleted%'     => $counts['deleted'],
            '%deactivated%' => $counts['deactivated'],
            '%reactivated%' => $counts['reactivated'],
        ], 'admin'));
        if ($import['teachers'] !== null) {
            $this->addFlash('success', $this->translator->trans('responsibilities.lists.import_seneca.flash.teachers', [
                '%added%'   => $counts['teachers_added'],
                '%removed%' => $counts['teachers_removed'],
            ], 'admin'));
        }

        return $this->redirectToRoute('app_responsibilities_lists', ['centreId' => $centre->getId()]);
    }

    /**
     * The tree of the file and the two options of a subjects import, the same for the preview and for applying: the layout (the
     * one chosen, or the one the list already has — see SenecaListImporter::detectLayout() — on the first preview) and whether to
     * associate the teachers (by default when the file has the «Profesor/a» column and the centre an active course).
     *
     * @return array{desired: SenecaImportNode[], layout: SenecaSubjectsLayout, detected: ?SenecaSubjectsLayout, mismatch: bool, teacherColumn: bool, hasYear: bool, withTeachers: bool, teachers: ?SenecaTeacherMatch}|Response
     */
    private function readImport(EducationalCentre $centre, SenecaListImportKind $kind, Request $request, string $content, string $rootName): array|Response
    {
        $missing = $this->parser->missingColumn($content, $kind);
        if ($missing !== null) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.missing_column') . ' «' . $missing . '»');

            return $this->renderForm($centre, $kind, $rootName);
        }

        $subjects = $kind === SenecaListImportKind::Subjects;
        $chosen   = $request->request->getBoolean('options');
        $detected = $subjects
            ? $this->importer->detectLayout($centre, $rootName, $this->parser->distinctValues($content, 'Unidad'), $this->parser->distinctValues($content, 'Materia'))
            : null;
        $layout = $subjects && $chosen
            ? SenecaSubjectsLayout::tryFrom($request->request->getString('layout')) ?? SenecaSubjectsLayout::ByGroup
            : $detected ?? SenecaSubjectsLayout::ByGroup;

        $desired = $this->parser->buildTree($content, $kind, $layout);
        if ($desired === []) {
            $this->addFlash('error', $this->t('responsibilities.lists.import_seneca.error.no_rows'));

            return $this->renderForm($centre, $kind, $rootName);
        }

        $teacherColumn = $subjects && $this->parser->hasTeacherColumn($content);
        $hasYear       = $centre->getActiveAcademicYear() !== null;
        $withTeachers  = $teacherColumn && $hasYear && (!$chosen || $request->request->getBoolean('with_teachers'));

        return [
            'desired'       => $desired,
            'layout'        => $layout,
            'detected'      => $detected,
            'mismatch'      => $detected !== null && $detected !== $layout,
            'teacherColumn' => $teacherColumn,
            'hasYear'       => $hasYear,
            'withTeachers'  => $withTeachers,
            'teachers'      => $withTeachers ? $this->teacherMatcher->match($centre, $desired) : null,
        ];
    }

    /** @param array{desired: SenecaImportNode[], layout: SenecaSubjectsLayout, detected: ?SenecaSubjectsLayout, mismatch: bool, teacherColumn: bool, hasYear: bool, withTeachers: bool, teachers: ?SenecaTeacherMatch} $import */
    private function renderPreview(EducationalCentre $centre, SenecaListImportKind $kind, string $rootName, string $content, array $import): Response
    {
        $plan = $this->importer->plan($centre, $rootName, $import['desired'], $import['teachers'], !$import['mismatch']);

        return $this->render('admin/list_item/import_seneca.html.twig', [
            'centre'        => $centre,
            'kind'          => $kind,
            'rootName'      => $rootName,
            'plan'          => $plan,
            'formUsage'     => $this->importer->formUsage($plan),
            'csvBase64'     => base64_encode($content),
            'layout'        => $import['layout'],
            'detected'      => $import['detected'],
            'mismatch'      => $import['mismatch'],
            'teacherColumn' => $import['teacherColumn'],
            'hasYear'       => $import['hasYear'],
            'withTeachers'  => $import['withTeachers'],
        ]);
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
