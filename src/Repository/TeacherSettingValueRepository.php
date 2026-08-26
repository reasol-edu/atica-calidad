<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\Teacher;
use App\Entity\TeacherSettingValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeacherSettingValue>
 */
class TeacherSettingValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherSettingValue::class);
    }

    /** @return array<string, TeacherSettingValue> keyed by setting key */
    public function findByTeacherIndexedByKey(Teacher $teacher): array
    {
        $rows = $this->createQueryBuilder('v')
            ->join('v.definition', 'd')
            ->addSelect('d')
            ->where('v.teacher = :teacher')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->getDefinition()->getKey()] = $row;
        }

        return $result;
    }

    public function findByDefinitionAndTeacher(SettingDefinition $definition, Teacher $teacher): ?TeacherSettingValue
    {
        return $this->findOneBy(['definition' => $definition, 'teacher' => $teacher]);
    }

    public function countByFile(SettingFile $file): int
    {
        return (int) $this->count(['file' => $file]);
    }
}
