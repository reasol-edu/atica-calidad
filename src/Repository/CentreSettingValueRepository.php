<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CentreSettingValue>
 */
class CentreSettingValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CentreSettingValue::class);
    }

    /** @return array<string, CentreSettingValue> keyed by setting key */
    public function findByCentreIndexedByKey(EducationalCentre $centre): array
    {
        $rows = $this->createQueryBuilder('v')
            ->join('v.definition', 'd')
            ->addSelect('d')
            ->where('v.centre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->getDefinition()->getKey()] = $row;
        }

        return $result;
    }

    public function findByDefinitionAndCentre(SettingDefinition $definition, EducationalCentre $centre): ?CentreSettingValue
    {
        return $this->findOneBy(['definition' => $definition, 'centre' => $centre]);
    }

    public function countByFile(SettingFile $file): int
    {
        return (int) $this->count(['file' => $file]);
    }
}
