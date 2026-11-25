<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\SenecaListImportKind;
use App\Model\SenecaSubjectsLayout;
use App\Repository\ListItemRepository;
use App\Service\SenecaListCsvParser;
use App\Service\SenecaListImporter;
use App\Service\SenecaTeacherMatcher;
use App\Tests\Integration\RepositoryTestCase;

/**
 * The teachers and the layout of a Séneca subjects import: the elements of the file end up with exactly the teachers it names
 * (found in the active course), the rest are left alone, and the layout a list already has is recognised.
 */
final class SenecaListImporterTeachersTest extends RepositoryTestCase
{
    private const CSV = <<<'CSV'
        "Curso","Materia","Unidad","Profesor/a"
        "1º ESO","Matemáticas","1º ESO A","Bravo Escudero, Noelia"
        "1º ESO","Matemáticas","1º ESO A","pardo esteban, marcos"
        "1º ESO","Lengua","1º ESO A","Gómez Pérez, Ana"
        "1º ESO","Matemáticas","1º ESO B","Bravo Escudero, Noelia"
        "1º ESO","Inglés","1º ESO B","Ruiz Lara, Pedro"
        CSV;

    private SenecaListImporter $importer;
    private SenecaListCsvParser $parser;
    private SenecaTeacherMatcher $matcher;
    private ListItemRepository $items;
    private EducationalCentre $centre;
    private Teacher $noelia;
    private Teacher $marcos;
    private Teacher $pedro;

    protected function setUp(): void
    {
        parent::setUp();

        $container      = self::getContainer();
        $this->importer = $container->get(SenecaListImporter::class);
        $this->parser   = $container->get(SenecaListCsvParser::class);
        $this->matcher  = $container->get(SenecaTeacherMatcher::class);
        $this->items    = $container->get(ListItemRepository::class);

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->noelia = $this->teacher('Noelia', 'Bravo Escudero');
        $this->marcos = $this->teacher('Marcos', 'Pardo Esteban');
        // Pedro exists but is not in the course; Ana does not exist at all.
        $this->pedro = $this->teacher('Pedro', 'Ruiz Lara');
        $year        = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre)
            ->addTeacher($this->noelia)->addTeacher($this->marcos);
        $this->persist($this->centre, $this->noelia, $this->marcos, $this->pedro, $year);
        $this->centre->setActiveAcademicYear($year);
        $this->flush();
    }

    private function teacher(string $first, string $last): Teacher
    {
        return (new Teacher(new PersonName($first, $last)))->setUsername(mb_strtolower($first) . random_int(1, PHP_INT_MAX));
    }

    /** @return array{0: \App\Model\SenecaImportNode[], 1: \App\Model\SenecaTeacherMatch} */
    private function read(string $csv = self::CSV, SenecaSubjectsLayout $layout = SenecaSubjectsLayout::ByGroup): array
    {
        $desired = $this->parser->buildTree($csv, SenecaListImportKind::Subjects, $layout);

        return [$desired, $this->matcher->match($this->centre, $desired)];
    }

    private function leaf(string ...$path): ListItem
    {
        $node = null;
        foreach ($path as $name) {
            $candidates = $node === null ? $this->items->findRootsByCentre($this->centre) : $this->items->findChildrenByParent($node);
            $node       = array_values(array_filter($candidates, static fn (ListItem $i): bool => $i->getName() === $name))[0] ?? null;
            self::assertNotNull($node, 'no ' . implode(' › ', $path));
        }
        self::assertNotNull($node);

        return $node;
    }

    /** @return list<string> */
    private function teachersOf(ListItem $item): array
    {
        $names = array_map(static fn (Teacher $t): string => $t->getName()->full(), $item->getTeachers());
        sort($names);

        return $names;
    }

    public function testTheNamesAreFoundInTheCourseOnlyAndTheRestSayWhy(): void
    {
        // A namesake of Noelia in another centre must not be the one found.
        $other     = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Ciudad');
        $namesake  = $this->teacher('Noelia', 'Bravo Escudero');
        $otherYear = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($other)->addTeacher($namesake);
        $this->persist($other, $namesake, $otherYear);
        $this->flush();

        [, $match] = $this->read();

        self::assertSame($this->noelia->getId()->toRfc4122(), $match->found['Bravo Escudero, Noelia']->getId()->toRfc4122());
        self::assertSame($this->marcos->getId()->toRfc4122(), $match->found['pardo esteban, marcos']->getId()->toRfc4122(), 'case does not matter');
        self::assertSame(['Gómez Pérez, Ana'], $match->notFound);
        self::assertSame(['Ruiz Lara, Pedro'], $match->notInCourse);
        self::assertSame(2, $match->unresolvedCount());
    }

    public function testANewListGetsTheTeachersOfEachSubjectOfEachGroup(): void
    {
        [$desired, $match] = $this->read();

        $plan = $this->importer->plan($this->centre, 'Materias', $desired, $match);
        self::assertSame(3, $plan->teacherLinks, 'Noelia and Marcos in 1º ESO A, Noelia in 1º ESO B');
        self::assertSame(
            [['1º ESO A', 'Matemáticas'], ['1º ESO B', 'Matemáticas']],
            array_map(static fn ($c): array => $c->path, $plan->teacherChanges),
        );
        self::assertSame(['Noelia Bravo Escudero', 'Marcos Pardo Esteban'], $plan->teacherChanges[0]->added);

        $counts = $this->importer->apply($this->centre, 'Materias', $desired, true, $match);
        self::assertSame(3, $counts['teachers_added']);

        $this->em->clear();
        self::assertSame(['Marcos Pardo Esteban', 'Noelia Bravo Escudero'], $this->teachersOf($this->leaf('Materias', '1º ESO A', 'Matemáticas')));
        self::assertSame([], $this->teachersOf($this->leaf('Materias', '1º ESO A', 'Lengua')), 'Ana was not found');
        self::assertSame([], $this->teachersOf($this->leaf('Materias', '1º ESO A')), 'the group itself gets none');
    }

    public function testReImportingForANewCourseOnlyChangesTheTeachersAndLeavesTheOtherElementsAlone(): void
    {
        [$desired, $match] = $this->read();
        $this->importer->apply($this->centre, 'Materias', $desired, true, $match);

        // Something added by hand: an element the file does not mention, and a profile association, keep theirs.
        $this->em->clear();
        $centre  = $this->em->find(EducationalCentre::class, $this->centre->getId());
        self::assertNotNull($centre);
        $extra   = (new ListItem())->setEducationalCentre($centre)->setName('Tutoría')->setPosition(9)->setParent($this->leaf('Materias', '1º ESO A'));
        $marcos  = $this->em->find(Teacher::class, $this->marcos->getId());
        self::assertNotNull($marcos);
        $extra->addTeacher($marcos);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $this->persist($extra, $profile);
        $this->leaf('Materias', '1º ESO A', 'Matemáticas')->addProfileAssociation($profile);
        $this->flush();

        // Next course: Marcos leaves 1º ESO A Matemáticas and takes 1º ESO B's.
        $next = <<<'CSV'
            "Curso","Materia","Unidad","Profesor/a"
            "1º ESO","Matemáticas","1º ESO A","Bravo Escudero, Noelia"
            "1º ESO","Lengua","1º ESO A","Gómez Pérez, Ana"
            "1º ESO","Matemáticas","1º ESO B","Pardo Esteban, Marcos"
            "1º ESO","Inglés","1º ESO B","Ruiz Lara, Pedro"
            "1º ESO","Tutoría","1º ESO A",""
            CSV;
        $this->em->clear();
        [$desired, $match] = $this->read($next);
        $plan = $this->importer->plan($this->centre, 'Materias', $desired, $match);

        self::assertSame([], $plan->additions);
        self::assertSame([], $plan->deletions);
        self::assertFalse($plan->isEmpty(), 'only teachers change: still something to apply');
        $changes = [];
        foreach ($plan->teacherChanges as $change) {
            $changes[$change->label()] = [$change->added, $change->removed];
        }
        self::assertSame([
            '1º ESO A › Matemáticas' => [[], ['Marcos Pardo Esteban']],
            '1º ESO A › Tutoría'     => [[], ['Marcos Pardo Esteban']],
            '1º ESO B › Matemáticas' => [['Marcos Pardo Esteban'], ['Noelia Bravo Escudero']],
        ], $changes);

        $counts = $this->importer->apply($this->centre, 'Materias', $desired, true, $match);
        self::assertSame([2 => 1, 3 => 3], [2 => $counts['teachers_added'], 3 => $counts['teachers_removed']]);

        $this->em->clear();
        $maths = $this->leaf('Materias', '1º ESO A', 'Matemáticas');
        self::assertSame(['Noelia Bravo Escudero'], $this->teachersOf($maths), 'Noelia kept (not removed and re-added), Marcos gone');
        self::assertCount(1, $maths->getProfileAssociations(), 'profile associations are never touched');
        self::assertSame(['Marcos Pardo Esteban'], $this->teachersOf($this->leaf('Materias', '1º ESO B', 'Matemáticas')));
    }

    public function testWithoutTheTeachersTheirAssociationsAreNotTouched(): void
    {
        [$desired, $match] = $this->read();
        $this->importer->apply($this->centre, 'Materias', $desired, true, $match);
        $this->em->clear();

        [$desired] = $this->read("\"Materia\",\"Unidad\",\"Profesor/a\"\n\"Matemáticas\",\"1º ESO A\",\"\"\n");
        $plan      = $this->importer->plan($this->centre, 'Materias', $desired);
        self::assertSame([], $plan->teacherChanges);
        self::assertNull($plan->teacherMatch);
    }

    public function testBySubjectTheGroupsUnderEachSubjectGetTheTeachers(): void
    {
        [$desired, $match] = $this->read(self::CSV, SenecaSubjectsLayout::BySubject);
        $this->importer->apply($this->centre, 'Materias', $desired, true, $match);

        $this->em->clear();
        self::assertSame(['Marcos Pardo Esteban', 'Noelia Bravo Escudero'], $this->teachersOf($this->leaf('Materias', 'Matemáticas', '1º ESO A')));
        self::assertSame(['Noelia Bravo Escudero'], $this->teachersOf($this->leaf('Materias', 'Matemáticas', '1º ESO B')));
    }

    // ── layout ──────────────────────────────────────────────────────────────

    public function testTheLayoutOfTheListIsDetectedFromItsFirstLevelOrItsLeaves(): void
    {
        $units    = $this->parser->distinctValues(self::CSV, 'Unidad');
        $subjects = $this->parser->distinctValues(self::CSV, 'Materia');
        self::assertNull($this->importer->detectLayout($this->centre, 'Materias', $units, $subjects), 'no list yet');

        [$desired] = $this->read(self::CSV, SenecaSubjectsLayout::BySubject);
        $this->importer->apply($this->centre, 'Por materia', $desired, true);
        [$desired] = $this->read();
        $this->importer->apply($this->centre, 'Por grupo', $desired, true);
        $this->em->clear();
        self::assertSame(SenecaSubjectsLayout::BySubject, $this->importer->detectLayout($this->centre, 'Por materia', $units, $subjects));
        self::assertSame(SenecaSubjectsLayout::ByGroup, $this->importer->detectLayout($this->centre, 'Por grupo', $units, $subjects));

        // Subjects grouped in department categories: nothing on the first level tells, the leaves (groups) do.
        $centre = $this->em->find(EducationalCentre::class, $this->centre->getId());
        self::assertNotNull($centre);
        $root = (new ListItem())->setEducationalCentre($centre)->setName('Departamentos')->setPosition(5);
        $dept = (new ListItem())->setEducationalCentre($centre)->setName('Ciencias')->setPosition(0)->setParent($root);
        $sub  = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas')->setPosition(0)->setParent($dept);
        $grp  = (new ListItem())->setEducationalCentre($centre)->setName('1º ESO A')->setPosition(0)->setParent($sub);
        $this->persist($root, $dept, $sub, $grp);
        $this->flush();
        self::assertSame(SenecaSubjectsLayout::BySubject, $this->importer->detectLayout($this->centre, 'Departamentos', $units, $subjects));
    }

    public function testImportingWithTheOtherLayoutCreatesTheNewOneInsteadOfMatchingLeavesInside(): void
    {
        [$desired] = $this->read();
        $this->importer->apply($this->centre, 'Materias', $desired, true);
        $this->em->clear();

        [$bySubject] = $this->read(self::CSV, SenecaSubjectsLayout::BySubject);
        $plan        = $this->importer->plan($this->centre, 'Materias', $bySubject, null, false);

        self::assertContains(['Matemáticas'], array_map(static fn ($i): array => $i->path, $plan->additions), 'a new subject on top, not the leaf of a group');
        self::assertContains(['1º ESO A'], array_map(static fn ($i): array => $i->path, $plan->deletions), 'the old groups go');
    }
}
