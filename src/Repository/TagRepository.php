<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /** @return Tag[] */
    public function findByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByNameInsensitive(EducationalCentre $centre, string $name): ?Tag
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.educationalCentre = :centre')
            ->andWhere('LOWER(t.name) = LOWER(:name)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Tag ? $result : null;
    }

    /** Whether any list item still has this tag attached. */
    public function isOrphan(Tag $tag): bool
    {
        $count = (int) $this->getEntityManager()->createQuery('
                SELECT COUNT(li.id)
                FROM App\Entity\ListItem li
                WHERE :tag MEMBER OF li.tags
            ')
            ->setParameter('tag', $tag->getId(), 'uuid')
            ->getSingleScalarResult();

        return $count === 0;
    }
}
