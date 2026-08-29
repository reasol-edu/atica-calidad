<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class DashboardActivitySummaryComponentTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function category(EducationalCentre $centre): ActivityCategory
    {
        return (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
    }

    private function activity(ActivityCategory $category, string $title = 'Actividad'): Activity
    {
        return (new Activity())->setCategory($category)->setTitle($title)->setStart(1, 9)->setEnd(30, 9);
    }

    public function testShowsThePositiveEmptyStateWhenNothingApplies(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('¡Todo al día!', $html);
    }

    public function testListsAPendingActivity(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category, 'Lectura de la política de calidad');
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Lectura de la política de calidad', $html);
        self::assertStringContainsString('Pendiente', $html);
    }

    public function testShowsTheOverdueAlertForAnUncompletedActivityPastItsDeadline(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category, 'Memoria final');
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Memoria final', $html);
        self::assertStringContainsString('Vencida', $html);
        self::assertStringContainsString('plazo vencido', $html);
    }

    public function testACompletedActivityIsNotListedButCountsInTheStats(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category, 'Actividad completada');
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher));

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('Actividad completada', $html);
        self::assertStringContainsString('¡Todo al día!', $html);
    }
}
