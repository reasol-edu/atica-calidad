<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TeacherRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TEACHER')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TeacherRepository $teacherRepository,
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

        return $this->json(['groups' => $groups]);
    }
}
