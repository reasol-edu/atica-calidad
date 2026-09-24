<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingStatus;
use App\Entity\ImprovementAction;
use App\Entity\Teacher;
use App\Repository\DocumentSectionRepository;
use App\Repository\FindingRepository;
use App\Repository\ImprovementActionRepository;
use App\Repository\QualityAttachmentRepository;
use App\Security\Voter\QualityVoter;
use App\Service\AttachmentDownloadResponder;
use App\Service\FindingService;
use App\Service\QualityTaskFinder;
use App\Service\SectionChoiceBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Mejora continua": the hub (inbox, what's yours to do, what you reported), reporting an
 * incident, the findings list and a finding's page (FindingDetailComponent does the work there),
 * plus attaching and downloading files. Everything below needs the current centre; beyond that,
 * see QualityVoter.
 */
#[Route('/mejora')]
class QualityController extends AbstractController
{
    use UploadSizeGuardTrait;

    /** Most files per report or per upload, and the size of each. */
    public const int MAX_FILES     = 5;
    public const int MAX_FILE_SIZE = 20 * 1024 * 1024;

    public function __construct(
        private readonly FindingRepository $findings,
        private readonly ImprovementActionRepository $actions,
        private readonly QualityAttachmentRepository $attachments,
        private readonly DocumentSectionRepository $sections,
        private readonly FindingService $findingService,
        private readonly QualityTaskFinder $tasks,
        private readonly SectionChoiceBuilder $sectionChoices,
        private readonly AttachmentDownloadResponder $downloadResponder,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_quality_index')]
    public function index(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $teacher = $this->teacher();

        return $this->render('quality/index.html.twig', [
            'centre'    => $centre,
            'isManager' => $this->isGranted(QualityVoter::MANAGE, $centre),
            'seesAll'   => $this->isGranted(QualityVoter::VIEW_ALL, $centre),
            'inbox'     => $this->isGranted(QualityVoter::MANAGE, $centre) ? $this->findings->findByCentreAndStatus($centre, FindingStatus::Reported) : [],
            'counts'    => $this->isGranted(QualityVoter::VIEW_ALL, $centre) ? $this->findings->countByStatus($centre) : [],
            'tasks'     => $this->tasks->forTeacher($teacher, $centre),
            'mine'      => $this->findings->findReportedBy($teacher, $centre),
        ]);
    }

    #[Route('/comunicar', name: 'app_quality_report', methods: ['GET', 'POST'])]
    public function report(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $teacher = $this->teacher();
        $errors  = [];
        $values  = ['description' => '', 'section' => ''];

        if ($request->isMethod('POST')) {
            if ($this->isUploadTooLarge($request)) {
                $errors['files'] = $this->t('report.error.too_large');
            } elseif (!$this->isCsrfTokenValid('quality_report', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            } else {
                $values = ['description' => trim($request->request->getString('description')), 'section' => $request->request->getString('section')];
                $files  = $this->uploadedFiles($request, $errors);
                if ($values['description'] === '') {
                    $errors['description'] = $this->t('report.error.description_required');
                }
                $section = $values['section'] === '' ? null : $this->sections->findByIdAndCentre($values['section'], $centre);

                if ($errors === []) {
                    $finding = $this->findingService->report($centre, $teacher, $values['description'], $section, $files);
                    $this->addFlash('success', $this->t('report.flash.sent'));

                    return $this->redirectToRoute('app_quality_finding', ['id' => $finding->getId()->toRfc4122()]);
                }
            }
        }

        return $this->render('quality/report.html.twig', [
            'centre'   => $centre,
            'sections' => $this->sectionChoices->choices($teacher, $centre),
            'values'   => $values,
            'errors'   => $errors,
            'maxFiles' => self::MAX_FILES,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/fichas', name: 'app_quality_findings')]
    public function list(#[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::VIEW_ALL, $centre);

        return $this->render('quality/list.html.twig', ['centre' => $centre]);
    }

    #[Route('/fichas/{id}', name: 'app_quality_finding')]
    public function show(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $finding = $this->requireFinding($id, $centre);

        return $this->render('quality/finding.html.twig', ['centre' => $centre, 'finding' => $finding]);
    }

    #[Route('/fichas/{id}/adjuntos', name: 'app_quality_finding_attach', methods: ['POST'])]
    public function attachToFinding(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $finding = $this->requireFinding($id, $centre);
        $this->upload($request, 'quality_attach_' . $id, $finding, null);

        return $this->redirectToRoute('app_quality_finding', ['id' => $id, '_fragment' => 'adjuntos']);
    }

    #[Route('/acciones/{id}/adjuntos', name: 'app_quality_action_attach', methods: ['POST'])]
    public function attachToAction(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $action = $this->actions->findByIdAndCentre($id, $centre);
        if ($action === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(QualityVoter::ACTION_WORK, $action);
        $finding = $action->getFinding();
        $this->upload($request, 'quality_attach_' . $id, $finding, $action);

        return $finding !== null
            ? $this->redirectToRoute('app_quality_finding', ['id' => $finding->getId()->toRfc4122(), '_fragment' => 'accion-' . $id])
            : $this->redirectToRoute('app_quality_action', ['id' => $id, '_fragment' => 'evidencias']);
    }

    #[Route('/adjuntos/{id}', name: 'app_quality_attachment', methods: ['GET'])]
    public function download(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $attachment = $this->attachments->findById($id);
        $finding    = $attachment?->getOwningFinding();
        $action     = $attachment?->getAction();
        if ($attachment === null) {
            throw $this->createNotFoundException();
        }
        if ($finding !== null) {
            if ($finding->getEducationalCentre() !== $centre) {
                throw $this->createNotFoundException();
            }
            $this->denyAccessUnlessGranted(QualityVoter::FINDING_VIEW, $finding);
        } elseif ($action !== null && $action->getEducationalCentre() === $centre) {
            // A plan action's evidence.
            $this->denyAccessUnlessGranted(QualityVoter::ACTION_VIEW, $action);
        } elseif ($attachment->getAuditItem()?->getAudit()->getEducationalCentre() === $centre) {
            // What was seen in an internal audit.
            $this->denyAccessUnlessGranted(QualityVoter::AUDIT_VIEW, $attachment->getAuditItem()->getAudit());
        } else {
            throw $this->createNotFoundException();
        }

        $file = $attachment->getFile();

        return $this->downloadResponder->respond($file->getContent(), $file->getMimeType(), $attachment->getFilename());
    }

    private function upload(Request $request, string $tokenId, ?Finding $finding, ?ImprovementAction $action): void
    {
        if ($this->isUploadTooLarge($request)) {
            $this->addFlash('error', $this->t('report.error.too_large'));

            return;
        }
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $errors = [];
        $files  = $this->uploadedFiles($request, $errors);
        if ($errors !== [] || $files === []) {
            $this->addFlash('error', $errors['files'] ?? $this->t('attachment.error.none'));

            return;
        }
        foreach ($files as $file) {
            $this->findingService->attach($finding, $action, $this->teacher(), $file);
        }
        $this->addFlash('success', $this->translator->trans('attachment.flash.added', ['%count%' => \count($files)], 'quality'));
    }

    /**
     * The request's "files[]", checked: at most MAX_FILES, each within MAX_FILE_SIZE.
     *
     * @param array<string, string> $errors
     *
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request, array &$errors): array
    {
        $files = array_values(array_filter(
            $request->files->all('files'),
            static fn (mixed $f): bool => $f instanceof UploadedFile && $f->getError() !== \UPLOAD_ERR_NO_FILE,
        ));
        if (\count($files) > self::MAX_FILES) {
            $errors['files'] = $this->translator->trans('report.error.too_many', ['%max%' => self::MAX_FILES], 'quality');

            return [];
        }
        foreach ($files as $file) {
            if (!$file->isValid() || $file->getSize() > self::MAX_FILE_SIZE) {
                $errors['files'] = $this->translator->trans('report.error.file', ['%name%' => $file->getClientOriginalName()], 'quality');

                return [];
            }
        }

        return $files;
    }

    private function requireFinding(string $id, EducationalCentre $centre): Finding
    {
        $finding = $this->findings->findByIdAndCentre($id, $centre);
        if ($finding === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(QualityVoter::FINDING_VIEW, $finding);

        return $finding;
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'quality');
    }
}
