<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Repository\EducationalCentreRepository;
use App\Repository\ListItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ListItemJsonExporter;
use App\Service\ListItemJsonImporter;
use App\Service\UploadLimits;
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

    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListItemRepository $items,
        private readonly EducationalCentreRepository $centres,
        private readonly ListItemJsonExporter $exporter,
        private readonly ListItemJsonImporter $importer,
        private readonly TranslatorInterface $translator,
        private readonly UploadLimits $uploadLimits,
    ) {}

    /** The centre's lists (root items), with how many elements each one holds; each is managed on its own screen. */
    #[Route('', name: 'app_responsibilities_lists')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        $byParent = [];
        foreach ($this->items->findAllByCentre($centre) as $item) {
            $byParent[$item->getParent()?->getId()->toRfc4122() ?? ''][] = $item;
        }

        $lists = [];
        foreach ($byParent[''] ?? [] as $root) {
            $lists[] = ['root' => $root, 'count' => $this->countDescendants($root->getId()->toRfc4122(), $byParent)];
        }

        return $this->render('admin/list_item/index.html.twig', [
            'centre' => $centre,
            'lists'  => $lists,
        ]);
    }

    #[Route('/nueva', name: 'app_responsibilities_lists_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->requireCsrf($request, 'list_create');

        $name = trim($request->request->getString('name'));
        if ($name === '') {
            $this->addFlash('error', $this->t('responsibilities.lists.index.error.name_required'));

            return $this->redirectToRoute('app_responsibilities_lists', ['centreId' => $centreId]);
        }

        $root = (new ListItem())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($this->items->nextRootPosition($centre));
        $this->em->persist($root);
        $this->em->flush();

        $this->addFlash('success', $this->t('responsibilities.lists.index.flash.created'));

        return $this->redirectToRoute('app_responsibilities_list_manage', ['centreId' => $centreId, 'rootId' => $root->getId()->toRfc4122()]);
    }

    #[Route('/{rootId}/subir', name: 'app_responsibilities_list_move_up', requirements: ['rootId' => self::UUID], methods: ['POST'])]
    public function moveUp(string $centreId, string $rootId, Request $request): Response
    {
        return $this->moveRoot($centreId, $rootId, $request, -1);
    }

    #[Route('/{rootId}/bajar', name: 'app_responsibilities_list_move_down', requirements: ['rootId' => self::UUID], methods: ['POST'])]
    public function moveDown(string $centreId, string $rootId, Request $request): Response
    {
        return $this->moveRoot($centreId, $rootId, $request, 1);
    }

    /** Elements of one list: the tree editor scoped to that root (a 404 for any id that is not one of this centre's roots). */
    #[Route('/{rootId}', name: 'app_responsibilities_list_manage', requirements: ['rootId' => self::UUID])]
    public function manage(string $centreId, string $rootId): Response
    {
        $centre = $this->requireCentre($centreId);
        $root   = $this->items->findByIdAndCentre($rootId, $centre);
        if ($root === null || !$root->isRoot()) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/list_item/manage.html.twig', [
            'centre' => $centre,
            'root'   => $root,
        ]);
    }

    private function moveRoot(string $centreId, string $rootId, Request $request, int $direction): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->requireCsrf($request, 'list_move');

        $roots = $this->items->findRootsByCentre($centre);
        foreach ($roots as $index => $root) {
            if ($root->getId()->toRfc4122() !== $rootId) {
                continue;
            }

            $other = $roots[$index + $direction] ?? null;
            if ($other !== null) {
                $position = $root->getPosition();
                $root->setPosition($other->getPosition());
                $other->setPosition($position);
                $this->em->flush();
            }

            return $this->redirectToRoute('app_responsibilities_lists', ['centreId' => $centreId]);
        }

        throw $this->createNotFoundException();
    }

    /**
     * @param array<string, ListItem[]> $byParent
     */
    private function countDescendants(string $parentKey, array $byParent): int
    {
        $count = 0;
        foreach ($byParent[$parentKey] ?? [] as $child) {
            $count += 1 + $this->countDescendants($child->getId()->toRfc4122(), $byParent);
        }

        return $count;
    }

    private function requireCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
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
            $this->addFlash('error', $this->uploadLimits->fileProblem($file) ?? $this->t('responsibilities.lists.import.error.no_file'));

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
