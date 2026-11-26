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
use App\Model\ProfileAssignmentRow;
use App\Service\DocumentTreeAccessChecker;
use App\Service\ListItemOwners;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** app:list-items:links-report and the rule it is about (ListItemOwners): an element of a list is owed by every profile on that list. */
final class ListItemsLinksReportCommandTest extends RepositoryTestCase
{
    private function report(): string
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:list-items:links-report'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        return $tester->getDisplay();
    }

    public function testTwoProfilesOnTheSameListBothOweTheElementAndTheReportSaysWhere(): void
    {
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $depts    = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $infor    = (new ListItem())->setEducationalCentre($centre)->setName('Informática')->setParent($depts);
        $lengua   = (new ListItem())->setEducationalCentre($centre)->setName('Lengua')->setParent($depts);
        $head     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura de departamento')->setListItem($depts);
        $coord    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Coordinación')->setListItem($depts);
        $guidance = (new SpecificProfile())->setEducationalCentre($centre)->setName('Orientación');
        $subjects = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $prog     = (new ListItem())->setEducationalCentre($centre)->setName('Programación')->setParent($subjects);
        $tutoring = (new ListItem())->setEducationalCentre($centre)->setName('Tutoría')->setParent($subjects);
        $prog->addProfileAssociation($head, $infor);
        $tutoring->addProfileAssociation($guidance);

        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $folder->addUploadProfile($head);
        $folder->addUploadProfile($coord);
        $folder->addUploadProfile($guidance);
        $activity = (new Activity())->setCategory($category)->setTitle('Programaciones')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder)->setListItem($subjects);
        $this->persist($centre, $depts, $infor, $lengua, $head, $coord, $guidance, $subjects, $prog, $tutoring, $category, $section, $folder, $activity);

        /** @var DocumentTreeAccessChecker $access */
        $access = self::getContainer()->get(DocumentTreeAccessChecker::class);
        /** @var ListItemOwners $owners */
        $owners = self::getContainer()->get(ListItemOwners::class);
        $rows   = $access->getFolderUploadRows($folder);

        $label = static fn (ProfileAssignmentRow $r): string => $r->displayName;
        self::assertSame(['Coordinación Informática', 'Jefatura de departamento Informática'], array_map($label, $owners->ownersOf($prog, $rows)), 'the element of «Departamento», whatever profile uses it; not Lengua');
        self::assertSame(['Orientación'], array_map($label, $owners->ownersOf($tutoring, $rows)), 'a plain profile, itself');

        $output = $this->report();
        self::assertMatchesRegularExpression('/Programaciones\s+Coordinación, Jefatura de departamento\s+2\s+3 \*/', $output, 'with one profile per list, Programación and Tutoría; now Programación twice');
        self::assertStringContainsString('* El número de entregas cambia', $output);
    }

    public function testTheDemoHasNoFolderWithTwoProfilesOfTheSameList(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $demo = new CommandTester((new Application($kernel))->find('app:load-demo-data'));
        $demo->execute([]);
        self::assertSame(0, $demo->getStatusCode());
        $this->em->clear();

        self::assertStringContainsString('nada cambia', $this->report());
    }
}
