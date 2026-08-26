<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SettingFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SettingFile>
 */
class SettingFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettingFile::class);
    }

    public function findByHash(string $hash): ?SettingFile
    {
        return $this->findOneBy(['hash' => $hash]);
    }
}
