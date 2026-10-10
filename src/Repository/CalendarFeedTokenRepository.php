<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarFeedToken;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarFeedToken>
 */
class CalendarFeedTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarFeedToken::class);
    }

    public function findByToken(string $token): ?CalendarFeedToken
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findFor(Teacher $teacher, EducationalCentre $centre): ?CalendarFeedToken
    {
        return $this->findOneBy(['teacher' => $teacher, 'educationalCentre' => $centre]);
    }
}
