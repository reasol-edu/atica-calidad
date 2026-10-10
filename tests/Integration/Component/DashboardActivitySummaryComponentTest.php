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

    /** Cycle key of $activity's occurrence "now" — what a completion made at this point would be stored against. */
    private function cycleKey(Activity $activity): int
    {
        /** @var \App\Service\ActivityDeadlineChecker $deadline */
        $deadline = self::getContainer()->get(\App\Service\ActivityDeadlineChecker::class);

        return $deadline->currentCycleKey($activity);
    }

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
        self::assertStringContainsString('No tienes ninguna actividad asignada', $html);
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

    public function testAManualActivityOffersMarkingItDoneAndAnUploadOneDoesNot(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $manual   = $this->activity($category, 'Lectura del plan');
        $section  = (new \App\Entity\DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new \App\Entity\Folder())->setDocumentSection($section)->setName('Memorias');
        $profile  = (new \App\Entity\SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);
        $withFolder = $this->activity($category, 'Memoria')->setFolder($folder);
        $teacher    = $this->teacher('docente');
        $year       = (new \App\Entity\AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->persist($centre, $year, $category, $section, $folder, $profile, $manual, $withFolder, $teacher, new \App\Entity\SpecificProfileAssignment($profile, null, $teacher));

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);
        $crawler   = $component->render()->crawler();

        self::assertCount(1, $crawler->filter('button[data-live-action-param=markDone]'), 'only the manual activity can be ticked off');
        self::assertStringContainsString('Memoria', $crawler->html());
    }

    public function testAManualActivityCanBeMarkedDoneFromTheList(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category, 'Lectura del plan');
        $teacher  = $this->teacher('docente');
        $year     = (new \App\Entity\AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->persist($centre, $year, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);

        // The action goes first: a render() leaves no HTTP response behind to read the props from.
        $component->call('markDone', ['activityId' => $activity->getId()->toRfc4122()]);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('Marcar hecha', $html, 'done: gone from the list');
        $this->em->clear();
        $completions = $this->em->getRepository(ActivityCompletion::class)->findAll();
        self::assertCount(1, $completions);
        self::assertSame('docente', $completions[0]->getCompletedBy()->getUsername());
    }

    public function testMarkingDoneOffersToUndoItAndUndoingBringsTheRowBack(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category, 'Lectura del plan');
        $teacher  = $this->teacher('docente');
        $year     = (new \App\Entity\AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->persist($centre, $year, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);
        $component->call('markDone', ['activityId' => $activity->getId()->toRfc4122()]);

        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('button[data-live-action-param=undoDone]'), 'the card offers to take it back');
        self::assertStringContainsString('«Lectura del plan» marcada como hecha', $crawler->html());

        $component->call('undoDone');

        $crawler = $component->render()->crawler();
        self::assertCount(0, $crawler->filter('button[data-live-action-param=undoDone]'));
        self::assertCount(1, $crawler->filter('button[data-live-action-param=markDone]'), 'the row is back in the list');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(ActivityCompletion::class)->findAll());
    }

    public function testDismissingTheUndoLineKeepsTheCompletion(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category, 'Lectura del plan');
        $teacher  = $this->teacher('docente');
        $year     = (new \App\Entity\AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->persist($centre, $year, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);
        $component->call('markDone', ['activityId' => $activity->getId()->toRfc4122()]);
        $component->call('dismissDone');

        self::assertCount(0, $component->render()->crawler()->filter('button[data-live-action-param=undoDone]'));
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(ActivityCompletion::class)->findAll());
    }

    public function testMarkingDoneIsRefusedForAnOwnerTheTeacherDoesNotHold(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category, 'Lectura del plan');
        $teacher  = $this->teacher('docente');
        $year     = (new \App\Entity\AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->persist($centre, $year, $category, $activity, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);

        // A manual general activity has no profile owner: any profile id is somebody else's row.
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $component->call('markDone', ['activityId' => $activity->getId()->toRfc4122(), 'profileId' => '01a12220-6291-7582-8306-beae519f8f46']);
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
        self::assertStringContainsString('1 vencida', $html);
    }

    public function testACompletedActivityIsNotListedButCountsInTheStats(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category, 'Actividad completada');
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher, $this->cycleKey($activity)));

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('DashboardActivitySummaryComponent', ['centre' => $centre], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('Actividad completada', $html);
        self::assertStringContainsString('¡Todo al día!', $html);
    }
}
