<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Repository\ActivityCategoryRepository;
use App\Repository\ActivityRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\FolderRepository;
use App\Repository\TeacherRepository;
use App\Service\DocumentTreeAccessChecker;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_TEACHER')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TeacherRepository $teacherRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentSectionRepository $documentSectionRepository,
        private readonly FolderRepository $folderRepository,
        private readonly ActivityCategoryRepository $activityCategoryRepository,
        private readonly ActivityRepository $activityRepository,
        private readonly DocumentTreeAccessChecker $documentTreeAccess,
        private readonly TranslatorInterface $translator,
        #[Target('search')]
        private readonly RateLimiterFactoryInterface $searchLimiter,
    ) {}

    #[Route('/buscar', name: 'app_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $limiter = $this->searchLimiter->create($this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?? 'anon');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['groups' => []], JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->json(['groups' => []]);
        }

        $q = trim($request->query->getString('q'));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return $this->json(['groups' => []]);
        }

        $groups = [];

        if ($this->isGranted('educational_centre.section', $centre)) {
            $year = $this->tenantContext->getViewYear($centre);
            if ($year !== null) {
                $teachers = $this->teacherRepository->searchByAcademicYear($year, $q);
                if ($teachers !== []) {
                    $groups['teachers'] = array_map(fn ($t) => [
                        'label'    => $t->getName()->getLastName() . ', ' . $t->getName()->getFirstName(),
                        'sublabel' => $t->getUsername(),
                        'url'      => $this->generateUrl('app_centre_teachers_index', [
                            'centreId' => $centre->getId()->toRfc4122(),
                        ]),
                    ], $teachers);
                }
            }
        }

        $user = $this->getUser();
        if ($user instanceof Teacher) {
            $sections = [];
            foreach ($this->documentSectionRepository->searchByCentre($centre, $q, 5) as $section) {
                if ($this->documentTreeAccess->canViewSection($user, $section)) {
                    $sections[] = $section;
                }
            }
            if ($sections !== []) {
                $groups['sections'] = array_map(fn (DocumentSection $s) => [
                    'label'    => $s->getName(),
                    'sublabel' => $this->sectionSearchPath($s),
                    'url'      => $this->generateUrl('app_document_tree', [
                        'section' => $s->getId()->toRfc4122(),
                    ]),
                ], $sections);
            }

            $folders = [];
            foreach ($this->folderRepository->searchByCentre($centre, $q, 5) as $folder) {
                if ($this->documentTreeAccess->canViewFolder($user, $folder)) {
                    $folders[] = $folder;
                }
            }
            if ($folders !== []) {
                $groups['folders'] = array_map(fn (Folder $f) => [
                    'label'    => $f->getName(),
                    'sublabel' => $this->folderPath($f),
                    'url'      => $this->generateUrl('app_document_tree', [
                        'section' => $f->getDocumentSection()->getId()->toRfc4122(),
                        'folder'  => $f->getId()->toRfc4122(),
                    ]),
                ], $folders);
            }

            $documents = [];
            foreach ($this->documentRepository->searchByCentre($centre, $q, 5) as $document) {
                if ($this->documentTreeAccess->canViewDocument($user, $document)) {
                    $documents[] = $document;
                }
            }
            if ($documents !== []) {
                $groups['documents'] = array_map(fn (Document $d) => [
                    'label'    => $d->getName(),
                    'sublabel' => $this->documentPath($d),
                    'url'      => $this->documentSearchUrl($d),
                ], $documents);
            }

            $categories = $this->activityCategoryRepository->searchByCentre($centre, $q, 5);
            if ($categories !== []) {
                $groups['activity_categories'] = array_map(fn (ActivityCategory $c) => [
                    'label'    => $c->getName(),
                    'sublabel' => $this->activityCategorySearchPath($c),
                    'url'      => $this->generateUrl('app_activities', [
                        'category' => $c->getId()->toRfc4122(),
                    ]),
                ], $categories);
            }

            $activities = [];
            foreach ($this->activityRepository->searchByCentre($centre, $q, 5) as $activity) {
                $folder = $activity->getFolder();
                if ($folder === null || $this->documentTreeAccess->canViewFolder($user, $folder)) {
                    $activities[] = $activity;
                }
            }
            if ($activities !== []) {
                $groups['activities'] = array_map(fn (Activity $a) => [
                    'label'    => $a->getTitle(),
                    'sublabel' => $this->activityCategoryTrail($a->getCategory()),
                    'url'      => $this->generateUrl('app_activities', [
                        'category' => $a->getCategory()->getId()->toRfc4122(),
                        'activity' => $a->getId()->toRfc4122(),
                    ]),
                ], $activities);
            }
        }

        return $this->json(['groups' => $groups]);
    }

    /** @return list<string> root-first names of a section's ancestor trail, including the section itself */
    private function sectionTrail(DocumentSection $section): array
    {
        $trail = [];
        for ($s = $section; $s !== null; $s = $s->getParent()) {
            array_unshift($trail, $s->getName());
        }

        return $trail;
    }

    private function sectionSearchPath(DocumentSection $section): string
    {
        $parent = $section->getParent();
        if ($parent === null) {
            return $this->translator->trans('breadcrumb.root', [], 'document_content');
        }

        return implode(' › ', $this->sectionTrail($parent));
    }

    private function folderPath(Folder $folder): string
    {
        return implode(' › ', $this->sectionTrail($folder->getDocumentSection()));
    }

    private function documentPath(Document $document): string
    {
        $folder  = $document->getFolder();
        $trail   = $this->sectionTrail($folder->getDocumentSection());
        $trail[] = $folder->getName();

        return implode(' › ', $trail);
    }

    /**
     * A document whose folder backs an Activity is a submission — send its ⌘K result to
     * Actividades (highlighted, same "pulse instead of forcing a panel open" treatment as a plain
     * document) rather than the document tree, since that's where its "who has to deliver it"
     * context lives.
     */
    private function documentSearchUrl(Document $document): string
    {
        $folder   = $document->getFolder();
        $activity = $folder->getActivity();
        if ($activity !== null) {
            return $this->generateUrl('app_activities', [
                'category'  => $activity->getCategory()->getId()->toRfc4122(),
                'activity'  => $activity->getId()->toRfc4122(),
                'highlight' => $document->getId()->toRfc4122(),
            ]);
        }

        return $this->generateUrl('app_document_tree', [
            'section'   => $folder->getDocumentSection()->getId()->toRfc4122(),
            'folder'    => $folder->getId()->toRfc4122(),
            'highlight' => $document->getId()->toRfc4122(),
        ]);
    }

    /** @return list<string> root-first names of a category's ancestor trail, including the category itself */
    private function activityCategoryTrailList(ActivityCategory $category): array
    {
        $trail = [];
        for ($c = $category; $c !== null; $c = $c->getParent()) {
            array_unshift($trail, $c->getName());
        }

        return $trail;
    }

    private function activityCategoryTrail(ActivityCategory $category): string
    {
        return implode(' › ', $this->activityCategoryTrailList($category));
    }

    private function activityCategorySearchPath(ActivityCategory $category): string
    {
        $parent = $category->getParent();
        if ($parent === null) {
            return $this->translator->trans('breadcrumb.root', [], 'activity_content');
        }

        return implode(' › ', $this->activityCategoryTrailList($parent));
    }
}
