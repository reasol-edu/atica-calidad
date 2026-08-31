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
use App\Twig\Components\MyActivitiesComponent;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class MyActivitiesComponentTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function category(EducationalCentre $centre, string $name = 'Categoría'): ActivityCategory
    {
        return (new ActivityCategory())->setEducationalCentre($centre)->setName($name);
    }

    private function activity(ActivityCategory $category, string $title = 'Actividad'): Activity
    {
        return (new Activity())->setCategory($category)->setTitle($title)->setStart(1, 9)->setEnd(30, 9);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testShowsThePositiveEmptyStateWhenNothingApplies(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('MyActivitiesComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('¡Todo al día!', $html);
    }

    public function testFlatViewIsSortedByDeadlineAscendingWithOverdueFirst(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        // Overdue (ended Sep 30) must sort before the still-pending one (ends Nov 30), even
        // though it was persisted second — the whole point of "por fecha límite" as the default.
        $later  = $this->activity($category, 'Vence en noviembre')->setStart(1, 11)->setEnd(30, 11);
        $sooner = $this->activity($category, 'Venció en septiembre')->setStart(1, 9)->setEnd(30, 9);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $category, $later, $sooner, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('MyActivitiesComponent', ['centre' => $centre], $this->client);
        $component->render();
        /** @var MyActivitiesComponent $instance */
        $instance = $component->component();
        $items    = $instance->getFilteredItems();

        self::assertCount(2, $items);
        self::assertSame('Venció en septiembre', $items[0]->activity->getTitle());
        self::assertSame('Vence en noviembre', $items[1]->activity->getTitle());
    }

    public function testSearchFiltersByTitleCategoryAndOwnerLabel(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre, 'Memoria anual');
        $matching = $this->activity($category, 'Otra cosa');
        $other    = $this->activity($this->category($centre, 'Diferente'), 'Cosa distinta');
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $matching, $other->getCategory(), $other, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('MyActivitiesComponent', ['centre' => $centre], $this->client);
        $component->set('searchQuery', 'memoria');
        $component->render();
        /** @var MyActivitiesComponent $instance */
        $instance = $component->component();
        $items    = $instance->getFilteredItems();

        self::assertCount(1, $items);
        self::assertSame('Otra cosa', $items[0]->activity->getTitle());
    }

    public function testGroupByStatusPutsPendingBeforeCompletedRegardlessOfDeadline(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre     = $this->centre();
        $category   = $this->category($centre);
        $pending    = $this->activity($category, 'Sin completar');
        $completedA = $this->activity($category, 'Completada');
        $teacher    = $this->teacher('docente');
        $this->persist($centre, $category, $pending, $completedA, $teacher, new ActivityCompletion($completedA, $teacher, null, null, $teacher));

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('MyActivitiesComponent', ['centre' => $centre], $this->client);
        $component->set('groupBy', 'status');
        $component->render();
        /** @var MyActivitiesComponent $instance */
        $instance = $component->component();
        $groups   = $instance->getGroups();

        self::assertCount(2, $groups);
        self::assertSame('Pendientes', $groups[0]['label']);
        self::assertSame('Sin completar', $groups[0]['items'][0]->activity->getTitle());
        self::assertSame('Completadas', $groups[1]['label']);
        self::assertSame('Completada', $groups[1]['items'][0]->activity->getTitle());
    }

    public function testGroupByCategorySplitsItemsAcrossCategoryGroups(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre  = $this->centre();
        $catA    = $this->category($centre, 'Categoría A');
        $catB    = $this->category($centre, 'Categoría B');
        $inA     = $this->activity($catA, 'De A');
        $inB     = $this->activity($catB, 'De B');
        $teacher = $this->teacher('docente');
        $this->persist($centre, $catA, $catB, $inA, $inB, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('MyActivitiesComponent', ['centre' => $centre], $this->client);
        $component->set('groupBy', 'category');
        $component->render();
        /** @var MyActivitiesComponent $instance */
        $instance = $component->component();
        $groups   = $instance->getGroups();

        self::assertCount(2, $groups);
        $labels = array_column($groups, 'label');
        sort($labels);
        self::assertSame(['Categoría A', 'Categoría B'], $labels);
    }

    public function testStatCountsReflectEachStatus(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre    = $this->centre();
        $category  = $this->category($centre);
        $overdue   = $this->activity($category, 'Vencida')->setStart(1, 9)->setEnd(30, 9);
        $pending   = $this->activity($category, 'Pendiente')->setStart(1, 11)->setEnd(30, 11);
        $completed = $this->activity($category, 'Completada')->setStart(1, 9)->setEnd(30, 9);
        $teacher   = $this->teacher('docente');
        $this->persist($centre, $category, $overdue, $pending, $completed, $teacher, new ActivityCompletion($completed, $teacher, null, null, $teacher));

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('MyActivitiesComponent', ['centre' => $centre], $this->client);
        $component->render();
        /** @var MyActivitiesComponent $instance */
        $instance = $component->component();

        self::assertSame(3, $instance->getTotal());
        self::assertSame(1, $instance->getOverdueCount());
        self::assertSame(1, $instance->getPendingCount());
        self::assertSame(1, $instance->getCompletedCount());
    }

    public function testOnlyPendingHidesCompletedItemsButKeepsPendingAndOverdue(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre    = $this->centre();
        $category  = $this->category($centre);
        $overdue   = $this->activity($category, 'Vencida')->setStart(1, 9)->setEnd(30, 9);
        $pending   = $this->activity($category, 'Pendiente')->setStart(1, 11)->setEnd(30, 11);
        $completed = $this->activity($category, 'Completada')->setStart(1, 9)->setEnd(30, 9);
        $teacher   = $this->teacher('docente');
        $this->persist($centre, $category, $overdue, $pending, $completed, $teacher, new ActivityCompletion($completed, $teacher, null, null, $teacher));

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('MyActivitiesComponent', ['centre' => $centre], $this->client);
        $component->set('onlyPending', true);
        $component->render();
        /** @var MyActivitiesComponent $instance */
        $instance = $component->component();

        $titles = array_map(static fn ($i) => $i->activity->getTitle(), $instance->getFilteredItems());
        sort($titles);
        self::assertSame(['Pendiente', 'Vencida'], $titles);

        // The stat tiles stay a fixed overview of everything — only the list itself is filtered.
        self::assertSame(3, $instance->getTotal());
        self::assertSame(1, $instance->getCompletedCount());
    }
}
