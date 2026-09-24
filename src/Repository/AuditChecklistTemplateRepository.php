<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditChecklistTemplate;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AuditChecklistTemplate>
 */
class AuditChecklistTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditChecklistTemplate::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?AuditChecklistTemplate
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->andWhere('t.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof AuditChecklistTemplate ? $result : null;
    }

    /**
     * The centre's library, in the order of the standard: by clause (naturally, so 10.2 comes
     * after 9.3), then by name; those without a clause last.
     *
     * @return list<AuditChecklistTemplate>
     */
    public function findByCentre(EducationalCentre $centre): array
    {
        /** @var list<AuditChecklistTemplate> $templates */
        $templates = $this->createQueryBuilder('t')
            ->where('t.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        usort($templates, static function (AuditChecklistTemplate $a, AuditChecklistTemplate $b): int {
            if (($a->getClause() === null) !== ($b->getClause() === null)) {
                return $a->getClause() === null ? 1 : -1;
            }

            return strnatcmp($a->getClause() ?? '', $b->getClause() ?? '') ?: strcasecmp($a->getName(), $b->getName());
        });

        return $templates;
    }
}
