<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Entity\TeacherSettingValue;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class SchoolEventListComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testMountDeniesAccessWithoutSectionPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->render();
    }

    public function testListsEventsOfTheViewedYear(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $event = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Claustro de septiembre')
            ->setGeneral(true);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $event, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Claustro de septiembre', $html);
    }

    public function testSearchFiltersByName(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $matching = (new SchoolEvent())
            ->setAcademicYear($year)->setDate(new \DateTimeImmutable('2025-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Reunión de departamento')->setGeneral(true);
        $notMatching = (new SchoolEvent())
            ->setAcademicYear($year)->setDate(new \DateTimeImmutable('2025-09-16'))
            ->setStartTime(new \DateTimeImmutable('09:00'))->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Evaluación inicial')->setGeneral(true);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $matching, $notMatching, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client);
        $component->set('search', 'departamento');

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Reunión de departamento', $html);
        self::assertStringNotContainsString('Evaluación inicial', $html);
    }

    public function testPaginationUsesTheTeachersOwnPageSizeSetting(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $def = (new SettingDefinition())->setKey('page.size')->setType(SettingType::Integer)->setDefaultValue('20')->setTeacherScope(true)->setMinValue(5)->setMaxValue(100);
        $value = (new TeacherSettingValue())->setDefinition($def)->setTeacher($admin)->setValue('1');

        $events = [];
        for ($i = 1; $i <= 3; ++$i) {
            $events[] = (new SchoolEvent())
                ->setAcademicYear($year)
                ->setDate(new \DateTimeImmutable("2025-09-1{$i}"))
                ->setStartTime(new \DateTimeImmutable('09:00'))
                ->setEndTime(new \DateTimeImmutable('10:00'))
                ->setName("Evento {$i}")
                ->setGeneral(true);
        }
        $this->persist($centre, $year, $admin, $def, $value, ...$events);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString(
            'Mostrando 1–1 de 3',
            $html,
            'the teacher configured page.size=1, so pagination should show 1 of 3 events per page instead of the default 20'
        );
    }

    public function testHasActiveFiltersReflectsSearchAndProfileFilterState(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client);
        /** @var \App\Twig\Components\SchoolEventListComponent $instance */
        $instance = $component->component();
        self::assertFalse($instance->hasActiveFilters());

        $component->set('search', 'algo');
        /** @var \App\Twig\Components\SchoolEventListComponent $instance */
        $instance = $component->component();
        self::assertTrue($instance->hasActiveFilters());
    }
}
