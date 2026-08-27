<?php

declare(strict_types=1);

namespace App\Autocomplete;

use App\Entity\Teacher;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\UX\Autocomplete\EntityAutocompleterInterface;

/**
 * @implements EntityAutocompleterInterface<Teacher>
 */
#[AutoconfigureTag('ux.entity_autocompleter', ['alias' => 'teacher_admin'])]
class TeacherAutocompleter implements EntityAutocompleterInterface
{
    public function getEntityClass(): string
    {
        return Teacher::class;
    }

    public function createFilteredQueryBuilder(EntityRepository $repository, string $query): QueryBuilder
    {
        $qb = $repository->createQueryBuilder('t');

        if (trim($query) === '') {
            // See TeacherCentreAutocompleter::createFilteredQueryBuilder() for why: the widget's
            // own JS fires a request with an empty query on focus, before the configured minimum
            // character count is reached.
            return $qb->andWhere('1 = 0');
        }

        $q = '%' . $query . '%';

        return $qb
            ->where('LOWER(t.name.firstName) LIKE LOWER(:q)')
            ->orWhere('LOWER(t.name.lastName) LIKE LOWER(:q)')
            ->orWhere('LOWER(t.username) LIKE LOWER(:q)')
            ->setParameter('q', $q)
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC');
    }

    public function getLabel(object $entity): string
    {
        return $entity->getName()->getLastName() . ', ' . $entity->getName()->getFirstName();
    }

    public function getValue(object $entity): mixed
    {
        return $entity->getId()->toRfc4122();
    }

    public function getAttributes(object $entity): array
    {
        return [];
    }

    public function isGranted(Security $security): bool
    {
        return $security->isGranted('ROLE_ADMIN');
    }

    public function getGroupBy(): mixed
    {
        return null;
    }
}
