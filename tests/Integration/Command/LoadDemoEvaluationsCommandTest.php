<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Activity;
use App\Model\ActivitySubmissionSlot;
use App\Repository\ActivityRepository;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** `app:load-demo-evaluations`: the activities with «Una entrega por» the manual's screenshots are taken from. */
final class LoadDemoEvaluationsCommandTest extends RepositoryTestCase
{
    public function testItBuildsTheReviewTheGroupedReportAndTheMinutesWithTheirStartingState(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        foreach (['app:load-demo-data', 'app:load-demo-module-programs', 'app:load-demo-evaluations'] as $name) {
            $tester = new CommandTester($application->find($name));
            $tester->execute([]);
            self::assertSame(0, $tester->getStatusCode(), $name);
        }
        // The commands' caches (the tree, the folders' upload rows…) hold the entities of before: everything is read afresh.
        $this->em->clear();
        self::getContainer()->get('services_resetter')->reset();

        /** @var ActivitySubmissionSlotBuilder $builder */
        $builder = self::getContainer()->get(ActivitySubmissionSlotBuilder::class);
        $byTitle = [];
        foreach (self::getContainer()->get(ActivityRepository::class)->findAll() as $activity) {
            $byTitle[$activity->getTitle()] = $activity;
        }
        $names = static fn (Activity $a): array => array_map(static fn (ActivitySubmissionSlot $s): string => $s->displayName, $builder->buildSlots($a));

        $review = $names($byTitle['Revisión de la programación']);
        self::assertCount(52, $review, '12 subjects with evaluations × 4, and Tecnología twice (two departments)');
        self::assertContains('Programación · Final 1', $review);
        self::assertNotContains('Programación · Ord.', $review, 'an FP module has no Ord.');
        self::assertNotContains('Educación Física · 1ª', $review, 'no evaluation, no submission');

        $report = $builder->buildSlots($byTitle['Informe de evaluación del departamento']);
        $owners = array_unique(array_map(static fn (ActivitySubmissionSlot $s): string => (string) $s->ownerLabel, $report));
        self::assertNotContains('Jefe/a de Departamento Educación Física', $owners, 'a department without subjects in any evaluation has no report');
        self::assertContains('Final 1', array_map(static fn (ActivitySubmissionSlot $s): string => $s->displayName, array_values(array_filter($report, static fn (ActivitySubmissionSlot $s): bool => $s->ownerLabel === 'Jefe/a de Departamento Informática'))));

        self::assertCount(27, $names($byTitle['Acta trimestral del departamento']), '9 departments × 3 terms');

        $delivered = 0;
        foreach ($builder->buildSlots($byTitle['Revisión de la programación']) as $slot) {
            $document = $builder->resolveSlot($byTitle['Revisión de la programación'], $slot);
            $delivered += $document !== null ? 1 : 0;
        }
        self::assertSame(3, $delivered, 'the 1ª of Programación, Bases de Datos and Matemáticas');

        $reports = [];
        foreach ($builder->buildSlots($byTitle['Informe de evaluación del departamento']) as $slot) {
            if ($builder->resolveSlot($byTitle['Informe de evaluación del departamento'], $slot) !== null) {
                $reports[] = $slot->ownerLabel . ' · ' . $slot->displayName;
            }
        }
        self::assertSame(['Jefe/a de Departamento Matemáticas · 1ª'], $reports, 'the report of Matemáticas of the 1ª, delivered');
    }
}
