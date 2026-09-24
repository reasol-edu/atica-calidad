<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AuditChecklistTemplate;
use App\Entity\EducationalCentre;
use App\Repository\AuditChecklistTemplateRepository;
use App\Security\Voter\QualityVoter;
use App\Service\AuditChecklistLibrary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The centre's library of audit checklists, for whoever manages "Mejora continua": loading the
 * ISO 9001 ones, editing, creating and deleting them, and exporting and importing them as JSON.
 */
#[Route('/mejora/auditorias/listas')]
class AuditChecklistController extends AbstractController
{
    public function __construct(
        private readonly AuditChecklistTemplateRepository $templates,
        private readonly AuditChecklistLibrary $library,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_quality_checklists')]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);

        return $this->render('quality/checklists.html.twig', [
            'centre'    => $centre,
            'templates' => $this->templates->findByCentre($centre),
        ]);
    }

    #[Route('/iso', name: 'app_quality_checklists_iso', methods: ['POST'])]
    public function loadIso(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $this->checkToken($request, 'quality_checklists');
        $added = $this->library->loadIso($centre);
        $this->addFlash('success', $this->translator->trans('checklist.flash.iso', ['%count%' => $added], 'quality'));

        return $this->redirectToRoute('app_quality_checklists');
    }

    #[Route('/importar', name: 'app_quality_checklists_import', methods: ['POST'])]
    public function import(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $this->checkToken($request, 'quality_checklists');
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > 2 * 1024 * 1024) {
            $this->addFlash('error', $this->t('checklist.error.file'));

            return $this->redirectToRoute('app_quality_checklists');
        }
        try {
            $added = $this->library->import($centre, (string) file_get_contents($file->getPathname()));
            $this->addFlash('success', $this->translator->trans('checklist.flash.imported', ['%count%' => $added], 'quality'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $this->t('checklist.error.' . $e->getMessage()));
        }

        return $this->redirectToRoute('app_quality_checklists');
    }

    #[Route('/exportar', name: 'app_quality_checklists_export')]
    public function export(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $one = $request->query->getString('lista');
        if ($one !== '') {
            $template = $this->templates->findByIdAndCentre($one, $centre) ?? throw $this->createNotFoundException();
            $list     = [$template];
            $name     = $template->getName();
        } else {
            $list = $this->templates->findByCentre($centre);
            $name = $this->t('checklist.export_name');
        }

        $response = new Response($this->library->export($list), 200, ['Content-Type' => 'application/json; charset=utf-8']);
        $filename = $name . '.json';
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, self::asciiFallback($filename)));

        return $response;
    }

    #[Route('/nueva', name: 'app_quality_checklist_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        return $this->form($request, $centre, null);
    }

    #[Route('/{id}', name: 'app_quality_checklist', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        return $this->form($request, $centre, $this->templates->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException());
    }

    #[Route('/{id}/eliminar', name: 'app_quality_checklist_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $template = $this->templates->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException();
        $this->checkToken($request, 'quality_checklist_' . $id);
        $this->library->delete($template);
        $this->addFlash('success', $this->t('checklist.flash.deleted'));

        return $this->redirectToRoute('app_quality_checklists');
    }

    private function form(Request $request, EducationalCentre $centre, ?AuditChecklistTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);

        $name   = $template?->getName() ?? '';
        $clause = $template?->getClause() ?? '';
        $rows   = array_map(static fn (array $i): array => ['clause' => $i['clause'] ?? '', 'question' => $i['question'], 'guidance' => $i['guidance'] ?? ''], $template?->getItems() ?? []);
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_checklist_' . ($template?->getId()->toRfc4122() ?? 'new'));
            $name   = trim($request->request->getString('name'));
            $clause = trim($request->request->getString('clause'));
            $rows   = [];
            $items  = [];
            foreach ($request->request->all('items') as $raw) {
                if (!\is_array($raw) || ($raw['remove'] ?? '') === '1') {
                    continue;
                }
                $row = [
                    'clause'   => \is_string($raw['clause'] ?? null) ? trim($raw['clause']) : '',
                    'question' => \is_string($raw['question'] ?? null) ? trim($raw['question']) : '',
                    'guidance' => \is_string($raw['guidance'] ?? null) ? trim($raw['guidance']) : '',
                ];
                if ($row['question'] === '' && $row['clause'] === '' && $row['guidance'] === '') {
                    continue;
                }
                $rows[] = $row;
                if ($row['question'] === '') {
                    $errors['items'] = $this->t('audit.error.question');

                    continue;
                }
                $items[] = ['clause' => $row['clause'] !== '' ? mb_substr($row['clause'], 0, 20) : null, 'question' => $row['question'], 'guidance' => $row['guidance'] !== '' ? $row['guidance'] : null];
            }
            if ($name === '') {
                $errors['name'] = $this->t('checklist.error.name');
            }
            if ($items === [] && !isset($errors['items'])) {
                $errors['items'] = $this->t('checklist.error.no_items');
            }
            if ($errors === []) {
                $template ??= new AuditChecklistTemplate($centre, $name, null);
                $this->library->save($template, $name, $clause, $items);
                $this->addFlash('success', $this->t('checklist.flash.saved'));

                return $this->redirectToRoute('app_quality_checklist', ['id' => $template->getId()->toRfc4122()]);
            }
        }

        return $this->render('quality/checklist.html.twig', [
            'centre'   => $centre,
            'template' => $template,
            'name'     => $name,
            'clause'   => $clause,
            'rows'     => $rows,
            'errors'   => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /** ASCII only: the Content-Disposition fallback name can't carry accents. */
    private static function asciiFallback(string $filename): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $filename);

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $ascii === false ? 'listas.json' : $ascii) ?? 'listas.json';
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'quality');
    }
}
