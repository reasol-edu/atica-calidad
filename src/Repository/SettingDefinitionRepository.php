<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SettingDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SettingDefinition>
 */
class SettingDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettingDefinition::class);
    }

    /** @return array<string, SettingDefinition> keyed by setting key */
    public function findAllIndexedByKey(): array
    {
        $result = [];
        foreach ($this->findAll() as $def) {
            $result[$def->getKey()] = $def;
        }

        return $result;
    }

    /** @return list<SettingDefinition> */
    public function findByScope(string $scope): array
    {
        $field = match ($scope) {
            'global'  => 'globalScope',
            'centre'  => 'centreScope',
            'teacher' => 'teacherScope',
            default   => throw new \InvalidArgumentException("Unknown scope: {$scope}"),
        };

        return $this->createQueryBuilder('d')
            ->where("d.{$field} = true")
            ->orderBy('d.categoryOrder', 'ASC')
            ->addOrderBy('d.position', 'ASC')
            ->addOrderBy('d.key', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
