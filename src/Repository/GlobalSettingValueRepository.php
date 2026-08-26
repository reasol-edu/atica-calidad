<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GlobalSettingValue;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GlobalSettingValue>
 */
class GlobalSettingValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GlobalSettingValue::class);
    }

    /** @return array<string, GlobalSettingValue> keyed by setting key */
    public function findAllIndexedByKey(): array
    {
        $rows = $this->createQueryBuilder('v')
            ->join('v.definition', 'd')
            ->addSelect('d')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->getDefinition()->getKey()] = $row;
        }

        return $result;
    }

    public function findByDefinition(SettingDefinition $definition): ?GlobalSettingValue
    {
        return $this->findOneBy(['definition' => $definition]);
    }

    public function countByFile(SettingFile $file): int
    {
        return (int) $this->count(['file' => $file]);
    }
}
