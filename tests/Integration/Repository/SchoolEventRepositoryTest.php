<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\SchoolEventRepository;
use App\Tests\Integration\RepositoryTestCase;

final class SchoolEventRepositoryTest extends RepositoryTestCase
{
    private function event(AcademicYear $year, string $name): SchoolEvent
    {
        return (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-08'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName($name)
            ->setGeneral(false);
    }

    /** @param list<SchoolEvent> $events @return list<string> */
    private function names(array $events): array
    {
        $names = array_map(static fn (SchoolEvent $e): string => $e->getName(), $events);
        sort($names);

        return $names;
    }

    /** Departamento > (ESO/Bach > Matemáticas) and (FP > Informática, Sanidad); Jefatura on the root, Secretaría plain. */
    public function testAnEventOnAnIntermediateNodeIsVisibleToTheHoldersOfTheLeavesUnderIt(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $eso    = (new ListItem())->setEducationalCentre($centre)->setName('ESO/Bach')->setParent($root);
        $mates  = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas')->setParent($eso);
        $fp     = (new ListItem())->setEducationalCentre($centre)->setName('FP')->setParent($root);
        $infor  = (new ListItem())->setEducationalCentre($centre)->setName('Informática')->setParent($fp);
        $sani   = (new ListItem())->setEducationalCentre($centre)->setName('Sanidad')->setParent($fp);
        $jefe   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($root);
        $secre  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretaría');

        $inInfor = (new Teacher(new PersonName('Ana', 'Informática')))->setUsername('infor');
        $inMates = (new Teacher(new PersonName('Eva', 'Matemáticas')))->setUsername('mates');
        $secret  = (new Teacher(new PersonName('Sol', 'Secretaría')))->setUsername('secre');
        $nobody  = (new Teacher(new PersonName('Nadie', 'Nadie')))->setUsername('nadie');

        $general = $this->event($year, 'general')->setGeneral(true);
        $onFp    = $this->event($year, 'fp')->addProfileRestriction($jefe, $fp);
        $onEso   = $this->event($year, 'eso')->addProfileRestriction($jefe, $eso);
        $onLeaf  = $this->event($year, 'sanidad')->addProfileRestriction($jefe, $sani);
        $onAll   = $this->event($year, 'todos-jefatura')->addProfileRestriction($jefe, null);
        $onSecre = $this->event($year, 'secretaria')->addProfileRestriction($secre, null);

        $this->persist(
            $centre, $year, $root, $eso, $mates, $fp, $infor, $sani, $jefe, $secre,
            $inInfor, $inMates, $secret, $nobody,
            new SpecificProfileAssignment($jefe, $infor, $inInfor),
            new SpecificProfileAssignment($jefe, $mates, $inMates),
            new SpecificProfileAssignment($secre, null, $secret),
            $general, $onFp, $onEso, $onLeaf, $onAll, $onSecre,
        );

        /** @var SchoolEventRepository $repo */
        $repo = self::getContainer()->get(SchoolEventRepository::class);
        $date = new \DateTimeImmutable('2025-09-08');

        self::assertSame(
            ['fp', 'general', 'todos-jefatura'],
            $this->names($repo->findVisibleForTeacherAndDate($inInfor, $year, $date)),
            'Informática hangs from FP; not ESO/Bach, not Sanidad',
        );
        self::assertSame(['eso', 'general', 'todos-jefatura'], $this->names($repo->findVisibleForTeacherAndDate($inMates, $year, $date)));
        self::assertSame(['general', 'secretaria'], $this->names($repo->findVisibleForTeacherAndDate($secret, $year, $date)));
        self::assertSame(['general'], $this->names($repo->findVisibleForTeacherAndDate($nobody, $year, $date)));
        self::assertSame(['fp', 'general', 'todos-jefatura'], $this->names($repo->findVisibleForTeacherInAcademicYear($inInfor, $year)));
    }
}
