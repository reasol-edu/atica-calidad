<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Model\SenecaImportNode;
use App\Model\SenecaListImportKind;
use App\Model\SenecaSubjectsLayout;
use App\Service\CsvReader;
use App\Service\SenecaListCsvParser;
use PHPUnit\Framework\TestCase;

final class SenecaListCsvParserTest extends TestCase
{
    private SenecaListCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SenecaListCsvParser(new CsvReader());
    }

    private const GROUPS_CSV = <<<'CSV'
        "Unidad","Tipo","Capacidad prevista","Nº de alumnos/as asignados/as","Tutor/a","Sede","Turno de tarde/noche","Curso"
        "1º DAM","PURA",30,21,"Sánchez Ramos, Enrique (01/09/2024-31/08/2025)",,"No","1º G.D.C.F.G.S. (Desarrollo de Aplicaciones Multiplataforma)"
        "2º DAM","PURA",20,9,"Serrano Hernández, Juan José (01/09/2024-31/08/2025)",,"No","2º F.P.I.G.S. (Desarrollo de Aplicaciones Multiplataforma)"
        CSV;

    private const SUBJECTS_CSV = <<<'CSV'
        "Curso","Materia","Unidad","Profesor/a"
        "1º F.P.I.G.S. (Desarrollo de Aplicaciones Multiplataforma)","Sistemas informáticos","1º DAM","Sánchez Ramos, Enrique"
        "2º F.P.I.G.S. (Desarrollo de Aplicaciones Multiplataforma)","Acceso a datos","2º DAM","Sánchez Ramos, Enrique"
        CSV;

    // ── requiredColumns / missingColumn ──────────────────────────────────────

    public function testGroupsImportOnlyRequiresUnidad(): void
    {
        self::assertSame(['Unidad'], $this->parser->requiredColumns(SenecaListImportKind::Groups));
    }

    public function testSubjectsImportRequiresUnidadAndMateria(): void
    {
        self::assertSame(['Unidad', 'Materia'], $this->parser->requiredColumns(SenecaListImportKind::Subjects));
    }

    public function testMissingColumnDetectsAnAbsentRequiredColumn(): void
    {
        self::assertSame('Materia', $this->parser->missingColumn(self::GROUPS_CSV, SenecaListImportKind::Subjects));
    }

    public function testMissingColumnReturnsNullWhenTheGroupsCsvHasWhatItNeeds(): void
    {
        self::assertNull($this->parser->missingColumn(self::GROUPS_CSV, SenecaListImportKind::Groups));
    }

    public function testMissingColumnReturnsNullWhenTheSubjectsCsvHasWhatItNeeds(): void
    {
        self::assertNull($this->parser->missingColumn(self::SUBJECTS_CSV, SenecaListImportKind::Subjects));
    }

    // ── isEmpty ───────────────────────────────────────────────────────────────

    public function testIsEmptyIsTrueForAnEmptyUpload(): void
    {
        self::assertTrue($this->parser->isEmpty(''));
    }

    public function testIsEmptyIsFalseOnceThereIsAHeaderRow(): void
    {
        self::assertFalse($this->parser->isEmpty(self::GROUPS_CSV));
    }

    // ── buildTree ─────────────────────────────────────────────────────────────

    public function testGroupsImportBuildsOneChildlessNodePerUnitAndOnlyReadsUnidad(): void
    {
        $nodes = $this->parser->buildTree(self::GROUPS_CSV, SenecaListImportKind::Groups);

        self::assertCount(2, $nodes);
        self::assertSame('1º DAM', $nodes[0]->name);
        self::assertSame('2º DAM', $nodes[1]->name);
        self::assertSame([], $nodes[0]->getChildren());
    }

    public function testSubjectsImportNestsEachSubjectUnderItsGroup(): void
    {
        $nodes = $this->parser->buildTree(self::SUBJECTS_CSV, SenecaListImportKind::Subjects);

        self::assertCount(2, $nodes);
        self::assertSame('1º DAM', $nodes[0]->name);
        $children = $nodes[0]->getChildren();
        self::assertCount(1, $children);
        self::assertSame('Sistemas informáticos', $children[0]->name);
        self::assertSame('2º DAM', $nodes[1]->name);
        self::assertSame('Acceso a datos', $nodes[1]->getChildren()[0]->name);
    }

    public function testSubjectsImportGroupsSeveralSubjectsUnderTheSameRepeatedUnidad(): void
    {
        $csv = <<<'CSV'
            "Curso","Materia","Unidad","Profesor/a"
            "1º DAM","Sistemas informáticos","1º DAM","Profesor A"
            "1º DAM","Bases de datos","1º DAM","Profesor B"
            CSV;

        $nodes = $this->parser->buildTree($csv, SenecaListImportKind::Subjects);

        self::assertCount(1, $nodes);
        $names = array_map(static fn (SenecaImportNode $n): string => $n->name, $nodes[0]->getChildren());
        self::assertSame(['Sistemas informáticos', 'Bases de datos'], $names);
    }

    public function testGroupsImportSkipsRowsWithABlankUnidad(): void
    {
        $csv = "Unidad\n\"1º DAM\"\n\"\"\n";

        $nodes = $this->parser->buildTree($csv, SenecaListImportKind::Groups);

        self::assertCount(1, $nodes);
        self::assertSame('1º DAM', $nodes[0]->name);
    }

    public function testSubjectsImportSkipsRowsWithABlankMateriaButKeepsTheGroupIfSeenElsewhere(): void
    {
        $csv = <<<'CSV'
            "Materia","Unidad"
            "","1º DAM"
            "Sistemas informáticos","1º DAM"
            CSV;

        $nodes = $this->parser->buildTree($csv, SenecaListImportKind::Subjects);

        self::assertCount(1, $nodes);
        self::assertSame('1º DAM', $nodes[0]->name);
        self::assertCount(1, $nodes[0]->getChildren());
        self::assertSame('Sistemas informáticos', $nodes[0]->getChildren()[0]->name);
    }

    // ── teachers and layout ─────────────────────────────────────────────────

    private const DESDOBLE_CSV = <<<'CSV'
        "Curso","Materia","Unidad","Profesor/a"
        "1º ESO","Matemáticas","1º ESO A","Bravo Escudero, Noelia"
        "1º ESO","Matemáticas","1º ESO A","Pardo  Esteban, Marcos"
        "1º ESO","Matemáticas","1º ESO A","Bravo Escudero, Noelia"
        "1º ESO","Lengua","1º ESO A",""
        "1º ESO","Matemáticas","1º ESO B","Bravo Escudero, Noelia"
        CSV;

    public function testEachSubjectOfAGroupGathersTheTeachersOfItsRowsOnceEach(): void
    {
        self::assertTrue($this->parser->hasTeacherColumn(self::DESDOBLE_CSV));
        $nodes = $this->parser->buildTree(self::DESDOBLE_CSV, SenecaListImportKind::Subjects);

        self::assertSame(['1º ESO A', '1º ESO B'], array_map(static fn (SenecaImportNode $n): string => $n->name, $nodes));
        self::assertFalse($nodes[0]->takesTeachers(), 'the group is not what the teachers teach');
        [$maths, $language] = $nodes[0]->getChildren();
        self::assertSame(['Bravo Escudero, Noelia', 'Pardo Esteban, Marcos'], $maths->getTeacherNames(), 'a desdoble adds both, repeated spaces collapsed');
        self::assertTrue($language->takesTeachers(), 'an empty «Profesor/a» still says nobody teaches it');
        self::assertSame([], $language->getTeacherNames());
    }

    public function testBySubjectTheSubjectsAreOnTopAndEachGroupCarriesItsTeachers(): void
    {
        $nodes = $this->parser->buildTree(self::DESDOBLE_CSV, SenecaListImportKind::Subjects, SenecaSubjectsLayout::BySubject);

        self::assertSame(['Matemáticas', 'Lengua'], array_map(static fn (SenecaImportNode $n): string => $n->name, $nodes));
        self::assertSame(['1º ESO A', '1º ESO B'], array_map(static fn (SenecaImportNode $n): string => $n->name, $nodes[0]->getChildren()));
        self::assertSame(['Bravo Escudero, Noelia', 'Pardo Esteban, Marcos'], $nodes[0]->getChildren()[0]->getTeacherNames());
        self::assertSame(['Bravo Escudero, Noelia'], $nodes[0]->getChildren()[1]->getTeacherNames());
    }

    public function testWithoutTheTeacherColumnNoNodeTakesTeachers(): void
    {
        $csv = "\"Materia\",\"Unidad\"\n\"Matemáticas\",\"1º ESO A\"\n";

        self::assertFalse($this->parser->hasTeacherColumn($csv));
        self::assertFalse($this->parser->buildTree($csv, SenecaListImportKind::Subjects)[0]->getChildren()[0]->takesTeachers());
    }

    public function testDistinctValuesAreLowerCasedOnceEach(): void
    {
        self::assertSame(['1º eso a' => true, '1º eso b' => true], $this->parser->distinctValues(self::DESDOBLE_CSV, 'Unidad'));
    }
}
