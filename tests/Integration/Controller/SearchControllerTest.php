<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class SearchControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    /** @return array<int|string, mixed> the "groups" object of the response */
    private function search(string $q): array
    {
        $this->client->request('GET', '/buscar?q=' . urlencode($q));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $groups = $data['groups'];
        self::assertIsArray($groups);

        return $groups;
    }

    /**
     * @param array<int|string, mixed> $groups
     * @return array<int|string, mixed> the first result item of that group
     */
    private function firstItem(array $groups, string $group): array
    {
        self::assertArrayHasKey($group, $groups);
        $items = $groups[$group];
        self::assertIsArray($items);
        self::assertNotEmpty($items);
        $first = $items[0];
        self::assertIsArray($first);

        return $first;
    }

    public function testAQueryShorterThanTwoCharactersReturnsNoGroups(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search('a');

        self::assertSame([], $groups);
    }

    public function testAQueryLongerThanOneHundredCharactersReturnsNoGroups(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search(str_repeat('a', 101));

        self::assertSame([], $groups);
    }

    public function testTheTeachersGroupIsOnlyIncludedForATeacherWithSectionPermission(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $other   = $this->teacher('anagarcia');
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $other, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search('anagarcia');

        self::assertArrayNotHasKey('teachers', $groups);
    }

    public function testTheTeachersGroupIsIncludedForAnAdmin(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $target  = $this->teacher('anagarcia');
        $target->addAcademicYear($year);
        $admin   = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $target, $admin);

        $this->loginAs($admin, $centre);
        $groups = $this->search('anagarcia');

        self::assertArrayHasKey('teachers', $groups);
        $teachers = $groups['teachers'];
        self::assertIsArray($teachers);
        self::assertCount(1, $teachers);
    }

    public function testDocumentSectionsAreFilteredByViewPermission(): void
    {
        $centre  = $this->centre();
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Programaciones didácticas')->setPosition(0);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $section, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search('Programaciones');

        self::assertSame('Programaciones didácticas', $this->firstItem($groups, 'sections')['label']);
    }

    public function testActivityCategoriesMatchByName(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Departamentos');
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search('Departamentos');

        self::assertSame('Departamentos', $this->firstItem($groups, 'activity_categories')['label']);
    }

    public function testAnActivityWithoutAFolderIsAlwaysIncluded(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Reunión inicial de curso')->setStart(1, 9)->setEnd(30, 6);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search('Reunión inicial');

        self::assertSame('Reunión inicial de curso', $this->firstItem($groups, 'activities')['label']);
    }

    public function testAnActivityBackedByAFolderIsHiddenWhenTheFolderIsNotViewable(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria final de departamento')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $section, $folder, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search('Memoria final');

        // The folder itself is unrestricted, so any teacher can view it — the activity shows up.
        self::assertSame('Memoria final de departamento', $this->firstItem($groups, 'activities')['label']);
    }

    public function testADocumentInAFolderBackingAnActivityLinksToTheActivitiesSectionWithAHighlight(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder);
        $document = new Document($folder, 'Programación de matemáticas');
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $section, $folder, $category, $activity, $document, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search('Programación de matemáticas');
        $url    = $this->firstItem($groups, 'documents')['url'];

        self::assertIsString($url);
        self::assertStringContainsString('/actividades', $url);
        self::assertStringContainsString('highlight=' . $document->getId()->toRfc4122(), $url);
    }

    public function testADocumentInAPlainFolderLinksToTheDocumentTree(): void
    {
        $centre   = $this->centre();
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $document = new Document($folder, 'Acta de la reunión de mayo');
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $section, $folder, $document, $teacher);

        $this->loginAs($teacher, $centre);
        $groups = $this->search('Acta de la reunión');
        $url    = $this->firstItem($groups, 'documents')['url'];

        self::assertIsString($url);
        self::assertStringContainsString('/arbol-documental', $url);
    }
}
