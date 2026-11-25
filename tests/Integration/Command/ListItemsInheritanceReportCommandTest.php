<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ListItemsInheritanceReportCommandTest extends RepositoryTestCase
{
    private function report(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:list-items:inheritance-report'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        return $tester;
    }

    public function testListsTheElementsThatNowInheritMoreThanTheirOwnAndWritesNothing(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $demo = new CommandTester((new Application($kernel))->find('app:load-demo-data'));
        $demo->execute([]);
        self::assertSame(0, $demo->getStatusCode());
        $this->em->clear();

        $connection = $this->em->getConnection();
        $snapshot   = static fn (): array => [
            $connection->fetchOne('SELECT COUNT(*) FROM list_item_profile'),
            $connection->fetchOne('SELECT COUNT(*) FROM list_item'),
            $connection->fetchOne('SELECT COUNT(*) FROM activity'),
        ];
        $before = $snapshot();

        $output = $this->report()->getDisplay();

        self::assertStringContainsString('2º DAW', $output, 'the demo element with an own association under an associated parent');
        self::assertStringContainsString('Tutor/a 2º DAW', $output);
        self::assertStringContainsString('Jefe/a de Departamento Informática', $output);
        self::assertMatchesRegularExpression('/Programaciones didácticas\s+(\d+)\s+\1\s/', $output, 'in the demo no activity gains submissions: the folder of the only activity does not accept both profiles');
        self::assertStringNotContainsString('* El número de entregas cambia', $output);
        self::assertSame($before, $snapshot(), 'read-only');
    }

    public function testListsEveryElementWhoseEffectiveSetChangesAndApartTheOwnOnesNowCovered(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $x      = (new SpecificProfile())->setEducationalCentre($centre)->setName('PerfilX');
        $y      = (new SpecificProfile())->setEducationalCentre($centre)->setName('PerfilY');
        $a      = (new ListItem())->setEducationalCentre($centre)->setName('A');
        $b      = (new ListItem())->setEducationalCentre($centre)->setName('B')->setParent($a);
        $c      = (new ListItem())->setEducationalCentre($centre)->setName('C')->setParent($b);
        $a->addProfileAssociation($x);
        $b->addProfileAssociation($y);

        // A profile with a list of its own: an intermediate subprofile covers the leaf under it.
        $anchor = (new ListItem())->setEducationalCentre($centre)->setName('Departamentos');
        $inter  = (new ListItem())->setEducationalCentre($centre)->setName('Área')->setParent($anchor);
        $dept   = (new ListItem())->setEducationalCentre($centre)->setName('Informática')->setParent($inter);
        $jefe   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($anchor);
        $top    = (new ListItem())->setEducationalCentre($centre)->setName('Enseñanza');
        $low    = (new ListItem())->setEducationalCentre($centre)->setName('DAW')->setParent($top);
        $top->addProfileAssociation($jefe, $inter);
        $low->addProfileAssociation($jefe, $dept);
        $this->persist($centre, $x, $y, $a, $b, $c, $anchor, $inter, $dept, $jefe, $top, $low);

        $output = $this->report()->getDisplay();

        self::assertMatchesRegularExpression('/A › B › C\s+PerfilY\s+PerfilX \(A\)/', $output, 'C has no own associations but now also takes A\'s');
        self::assertMatchesRegularExpression('/A › B\s+PerfilY\s+PerfilX \(A\)/', $output, 'B too');
        self::assertStringContainsString('ahora queda cubierta', $output);
        $covered = substr($output, (int) strpos($output, 'ahora queda cubierta'));
        self::assertMatchesRegularExpression('/Enseñanza › DAW\s+Jefatura Informática\s+Jefatura Área \(Enseñanza\)/', $covered, 'the own association is covered by the broader inherited one');
    }

    public function testCountsTheSubmissionsBeforeAndAfterTheAccumulation(): void
    {
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $jefatura = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $tutoria  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutoría');
        $folder->addUploadProfile($jefatura);
        $folder->addUploadProfile($tutoria);

        $root = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $leaf = (new ListItem())->setEducationalCentre($centre)->setName('Física')->setParent($root);
        $root->addProfileAssociation($jefatura);
        $leaf->addProfileAssociation($tutoria);
        $activity = (new Activity())->setCategory($category)->setTitle('Entrega de materias')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder)->setListItem($root);
        $this->persist($centre, $category, $section, $folder, $jefatura, $tutoria, $root, $leaf, $activity);

        $output = $this->report()->getDisplay();

        self::assertStringContainsString('Materia › Física', $output);
        self::assertMatchesRegularExpression('/Entrega de materias\s+1\s+2 \*/', $output, 'one submission today (its own Tutoría), two after (plus the inherited Jefatura)');
        self::assertStringContainsString('* El número de entregas cambia', $output);
    }
}
