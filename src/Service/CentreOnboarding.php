<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\SpecificProfileAssignment;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Primeros pasos" of a centre: the handful of things that make a new centre useful, each with whether it
 * is already done. Read by the dashboard card shown to whoever manages the centre, which disappears once
 * everything is done. Every step is a plain existence check, so it stays correct however the data got there
 * (manual entry, import, copying the previous year).
 */
final class CentreOnboarding
{
    public const string YEAR       = 'year';
    public const string TEACHERS   = 'teachers';
    public const string PROFILES   = 'profiles';
    public const string DOCUMENTS  = 'documents';
    public const string ACTIVITIES = 'activities';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeacherRepository $teachers,
    ) {}

    /**
     * Steps in the order they make sense: a year first (everything hangs from it), then the people, who holds
     * which responsibility, the documents and finally the activities that ask for them.
     *
     * @return list<array{key: string, done: bool}>
     */
    public function steps(EducationalCentre $centre): array
    {
        $year = $centre->getActiveAcademicYear();

        return [
            ['key' => self::YEAR,       'done' => $year !== null],
            // The person setting the centre up is already in it: "people" means someone besides them.
            ['key' => self::TEACHERS,   'done' => $year !== null && $this->teachers->countByAcademicYear($year) > 1],
            ['key' => self::PROFILES,   'done' => $this->exists(SpecificProfileAssignment::class, 'x', 'JOIN x.specificProfile p WHERE p.educationalCentre = :centre', $centre)],
            ['key' => self::DOCUMENTS,  'done' => $this->exists(Folder::class, 'x', 'JOIN x.documentSection s WHERE s.educationalCentre = :centre', $centre)],
            ['key' => self::ACTIVITIES, 'done' => $this->exists(Activity::class, 'x', 'JOIN x.category c WHERE c.educationalCentre = :centre', $centre)],
        ];
    }

    /** True while at least one step is still pending. */
    public function isPending(EducationalCentre $centre): bool
    {
        foreach ($this->steps($centre) as $step) {
            if (!$step['done']) {
                return true;
            }
        }

        return false;
    }

    /** @param class-string $class */
    private function exists(string $class, string $alias, string $joinAndWhere, EducationalCentre $centre): bool
    {
        $dql = \sprintf('SELECT %1$s.id FROM %2$s %1$s %3$s', $alias, $class, $joinAndWhere);

        return $this->em->createQuery($dql)
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setMaxResults(1)
            ->getOneOrNullResult() !== null;
    }
}
