<?php

declare(strict_types=1);

namespace App\Controller;

use App\Autocomplete\TeacherAutocompleter;
use App\Autocomplete\TeacherCentreAutocompleter;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Autocomplete\EntityAutocompleterInterface;

/**
 * Serves the same {results, next_page} JSON shape as Symfony UX Autocomplete's own
 * `ux_entity_autocomplete` route, for the same EntityAutocompleterInterface implementations —
 * but fetches with a plain `->getResult()` instead of wrapping the query in
 * Doctrine\ORM\Tools\Pagination\Paginator (which AutocompleteResultsExecutor::fetchResults() does
 * unconditionally, with no way for an autocompleter to opt out). That Paginator, when
 * fetchJoinCollection is true (its hardcoded default), re-fetches the page's rows via a second
 * "WHERE id IN (...)" query built by WhereInWalker — and on SQLite, with an entity whose id is a
 * binary-stored uuid, that second query's id array gets bound as a plain string array instead of
 * binary, corrupting every id and silently returning zero rows even though count() reports the
 * true total. Confirmed absent against PostgreSQL — a Doctrine/DBAL/SQLite-only interaction bug,
 * unrelated to anything in this app's own query logic. Since neither AutocompleteResultsExecutor
 * nor EntityAutocompleteController can be subclassed (both are final) or reconfigured (the
 * interface has no fetchJoinCollection hook), routing around the vendor controller for our own
 * autocompleters is the only fix available without patching vendor code.
 *
 * No page/next_page support: every current widget uses min-characters >= 2 and a small result set
 * (max 10), so there has never been a "load more" to serve.
 */
#[Route('/autocomplete-app')]
class AutocompleteController extends AbstractController
{
    /** @var array<string, EntityAutocompleterInterface<Teacher>> */
    private readonly array $autocompleters;

    public function __construct(
        private readonly Security $security,
        private readonly TeacherRepository $teachers,
        TeacherCentreAutocompleter $teacherCentre,
        TeacherAutocompleter $teacherAdmin,
    ) {
        $this->autocompleters = [
            'teacher_centre' => $teacherCentre,
            'teacher_admin'  => $teacherAdmin,
        ];
    }

    #[Route('/{alias}', name: 'app_entity_autocomplete', methods: ['GET'])]
    public function __invoke(string $alias, Request $request): Response
    {
        $autocompleter = $this->autocompleters[$alias] ?? null;
        if ($autocompleter === null) {
            throw $this->createNotFoundException(\sprintf('No autocompleter found for "%s".', $alias));
        }

        if (!$autocompleter->isGranted($this->security)) {
            throw $this->createAccessDeniedException();
        }

        // Both current autocompleters target Teacher; a future non-Teacher one would need its
        // own repository injected and mapped here too.
        $qb = $autocompleter->createFilteredQueryBuilder($this->teachers, $request->query->getString('query'));
        if (!$qb->getMaxResults()) {
            $qb->setMaxResults(10);
        }

        $rows = $qb->getQuery()->getResult();

        $results = [];
        foreach (is_array($rows) ? $rows : [] as $entity) {
            if (!$entity instanceof Teacher) {
                continue;
            }
            $results[] = [
                ...$autocompleter->getAttributes($entity),
                'value' => $autocompleter->getValue($entity),
                'text'  => $autocompleter->getLabel($entity),
            ];
        }

        return new JsonResponse(['results' => $results, 'next_page' => null]);
    }
}
