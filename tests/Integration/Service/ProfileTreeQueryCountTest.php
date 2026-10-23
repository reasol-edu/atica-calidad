<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\SchoolEventRepository;
use App\Service\DocumentTreeAccessChecker;
use App\Service\ProfileAssignmentRowBuilder;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * Restrictions on an intermediate node of a profile's list must cost a fixed number of queries,
 * however many leaves hang from it or how many of them a teacher holds: the centre's list tree is
 * read once and walked in memory (see ListItemRepository::findLeafIdsUnder()).
 */
final class ProfileTreeQueryCountTest extends RepositoryTestCase
{
    /**
     * Departamento > FP > $leaves leaves; the teacher holds all of them; $events events restricted to FP
     * (and, for the folder, visibility/upload profiles on FP).
     *
     * @return array{centre: EducationalCentre, year: AcademicYear, teacher: Teacher, profile: SpecificProfile, fp: ListItem, folder: Folder}
     */
    private function scenario(int $leaves, int $events): array
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $root    = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $fp      = (new ListItem())->setEducationalCentre($centre)->setName('FP')->setParent($root);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($root);
        $teacher = (new Teacher(new PersonName('Ana', 'Docente')))->setUsername('docente');
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $folder->addVisibilityProfile($profile, $fp);
        $folder->addUploadProfile($profile, $fp);
        $this->persist($centre, $year, $root, $fp, $profile, $teacher, $section, $folder);

        for ($i = 0; $i < $leaves; ++$i) {
            $leaf = (new ListItem())->setEducationalCentre($centre)->setName('Hoja ' . $i)->setParent($fp);
            $this->persist($leaf, new SpecificProfileAssignment($profile, $leaf, $teacher));
        }
        for ($i = 0; $i < $events; ++$i) {
            $event = (new SchoolEvent())
                ->setAcademicYear($year)
                ->setDate(new \DateTimeImmutable('2025-09-08'))
                ->setStartTime(new \DateTimeImmutable('09:00'))
                ->setEndTime(new \DateTimeImmutable('10:00'))
                ->setName('Evento ' . $i)
                ->setGeneral(false);
            $event->addProfileRestriction($profile, $fp);
            $this->persist($event);
        }

        return ['centre' => $centre, 'year' => $year, 'teacher' => $teacher, 'profile' => $profile, 'fp' => $fp, 'folder' => $folder];
    }

    /** Runs $work with the services' per-request memos cleared, and returns how many queries it issued. */
    private function queriesOf(callable $work): int
    {
        foreach ([DocumentTreeAccessChecker::class, ProfileAssignmentRowBuilder::class, \App\Repository\ListItemRepository::class] as $service) {
            $instance = self::getContainer()->get($service);
            if ($instance instanceof \Symfony\Contracts\Service\ResetInterface) {
                $instance->reset();
            }
        }

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $work();

        return \count($holder->getData()['default'] ?? []);
    }

    public function testEventVisibilityCostsTheSameWhateverTheNumberOfHeldLeavesAndEvents(): void
    {
        $small = $this->scenario(2, 2);
        /** @var SchoolEventRepository $repo */
        $repo     = self::getContainer()->get(SchoolEventRepository::class);
        $date     = new \DateTimeImmutable('2025-09-08');
        $smallQ   = $this->queriesOf(static fn () => $repo->findVisibleForTeacherAndDate($small['teacher'], $small['year'], $date));
        self::assertGreaterThan(0, $smallQ, 'the query log is on, so the numbers below mean something');

        $this->tearDown();
        $this->setUp();
        $big    = $this->scenario(25, 20);
        $repo   = self::getContainer()->get(SchoolEventRepository::class);
        $bigQ   = $this->queriesOf(static fn () => $repo->findVisibleForTeacherInAcademicYear($big['teacher'], $big['year']));

        // findVisibleForTeacherInAcademicYear() preloads the restrictions: one query more than the by-date one.
        self::assertLessThanOrEqual($smallQ + 1, $bigQ, "events visibility: {$smallQ} queries with 2 leaves, {$bigQ} with 25 leaves and 20 events");
    }

    public function testSubmissionSlotsCostTheSameWhateverTheNumberOfLeavesAndAssociations(): void
    {
        $measure = function (int $leaves): int {
            $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
            $category = (new \App\Entity\ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
            $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
            $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
            $dept     = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
            $deptFp   = (new ListItem())->setEducationalCentre($centre)->setName('FP')->setParent($dept);
            $jefe     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($dept);
            $folder->addUploadProfile($jefe, null);
            $evaluation = (new ListItem())->setEducationalCentre($centre)->setName('Evaluación');
            $branch     = (new ListItem())->setEducationalCentre($centre)->setName('FP')->setParent($evaluation);
            $branch->addProfileAssociation($jefe, $deptFp);
            $activity = (new \App\Entity\Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder)->setListItem($evaluation);
            $this->persist($centre, $category, $section, $folder, $dept, $deptFp, $jefe, $evaluation, $branch, $activity);
            for ($i = 0; $i < 4; ++$i) {
                $this->persist((new ListItem())->setEducationalCentre($centre)->setName('Subperfil ' . $i)->setParent($deptFp));
            }
            for ($i = 0; $i < $leaves; ++$i) {
                $leaf = (new ListItem())->setEducationalCentre($centre)->setName('Hoja ' . $i)->setParent($branch);
                if ($i % 3 === 0) {
                    $leaf->addProfileAssociation($jefe, $deptFp); // some leaves with associations of their own
                }
                $this->persist($leaf);
            }

            /** @var \App\Service\ActivitySubmissionSlotBuilder $builder */
            $builder = self::getContainer()->get(\App\Service\ActivitySubmissionSlotBuilder::class);
            $this->em->clear();
            $activity = $this->em->getReference(\App\Entity\Activity::class, $activity->getId());

            return $this->queriesOf(static function () use ($builder, $activity, $leaves): void {
                self::assertCount($leaves * 4, $builder->buildSlots($activity), 'one submission per leaf and per subprofile under FP');
            });
        };

        $small = $measure(3);
        $this->tearDown();
        $this->setUp();
        $big = $measure(24);

        self::assertGreaterThan(0, $small);
        self::assertLessThanOrEqual($small, $big, "submission slots: {$small} queries with 3 leaves, {$big} with 24");
    }

    public function testHoldingChecksAndUploadRowsCostTheSameWhateverTheNumberOfLeavesUnderTheNode(): void
    {
        $measure = function (int $leaves): int {
            $s      = $this->scenario($leaves, 0);
            /** @var DocumentTreeAccessChecker $access */
            $access = self::getContainer()->get(DocumentTreeAccessChecker::class);

            return $this->queriesOf(function () use ($access, $s, $leaves): void {
                $folder  = $s['folder'];
                $teacher = $s['teacher'];
                for ($i = 0; $i < 30; ++$i) {
                    self::assertTrue($access->canViewFolder($teacher, $folder));
                    self::assertTrue($access->canUploadToFolder($teacher, $folder));
                }
                self::assertCount($leaves, $access->getFolderUploadRows($folder));
            });
        };

        $small = $measure(2);
        $this->tearDown();
        $this->setUp();
        $big = $measure(25);

        self::assertGreaterThan(0, $small);
        self::assertLessThanOrEqual($small, $big, "folder checks: {$small} queries with 2 leaves, {$big} with 25 leaves");
    }
}
