<?php

declare(strict_types=1);

namespace App\Autocomplete;

use App\Entity\Teacher;
use App\Repository\AcademicYearRepository;
use App\Security\Voter\EducationalCentreVoter;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Autocomplete\EntityAutocompleterInterface;

/**
 * @implements EntityAutocompleterInterface<Teacher>
 */
#[AutoconfigureTag('ux.entity_autocompleter', ['alias' => 'teacher_centre'])]
class TeacherCentreAutocompleter implements EntityAutocompleterInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AcademicYearRepository $academicYears,
    ) {}

    public function getEntityClass(): string
    {
        return Teacher::class;
    }

    public function createFilteredQueryBuilder(EntityRepository $repository, string $query): QueryBuilder
    {
        $qb = $repository->createQueryBuilder('t');

        $academicYearId = trim(
            (string) ($this->requestStack->getCurrentRequest()?->query->getString('academicYearId') ?? '')
        );

        if ($academicYearId !== '') {
            $qb->join('t.academicYears', 'ay')
               ->where('ay.id = :yearId')
               ->setParameter('yearId', $academicYearId, 'uuid');
        }

        $q = '%' . $query . '%';

        return $qb
            ->andWhere(
                $qb->expr()->orX(
                    'LOWER(t.name.firstName) LIKE LOWER(:q)',
                    'LOWER(t.name.lastName) LIKE LOWER(:q)',
                    'LOWER(t.username) LIKE LOWER(:q)',
                )
            )
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
        $academicYearId = trim(
            (string) ($this->requestStack->getCurrentRequest()?->query->getString('academicYearId') ?? '')
        );

        if ($academicYearId === '' || !Uuid::isValid($academicYearId)) {
            return $security->isGranted('ROLE_ADMIN');
        }

        $year = $this->academicYears->findById($academicYearId);
        if ($year === null) {
            return false;
        }

        return $security->isGranted(EducationalCentreVoter::SECTION, $year->getEducationalCentre());
    }

    public function getGroupBy(): mixed
    {
        return null;
    }
}
