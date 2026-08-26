<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a new educational centre with its first academic year.
 *
 * Shared by app:setup and app:create-educational-centre, which would
 * otherwise duplicate this same sequence.
 */
final class CentreProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function provision(string $code, string $name, string $city, string $academicYearName): EducationalCentre
    {
        $centre = (new EducationalCentre())
            ->setCode($code)
            ->setName($name)
            ->setCity($city);

        $academicYear = (new AcademicYear())
            ->setName($academicYearName)
            ->setEducationalCentre($centre);

        $centre->setActiveAcademicYear($academicYear);

        $this->em->persist($centre);
        $this->em->persist($academicYear);

        $this->em->flush();

        return $centre;
    }
}
