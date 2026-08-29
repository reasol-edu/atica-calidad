<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
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
