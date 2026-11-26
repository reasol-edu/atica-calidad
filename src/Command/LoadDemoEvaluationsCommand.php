<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Repository\ActivityCategoryRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileRepository;
use App\Repository\TeacherRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Service\Form\FormSubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Optional addition to `app:load-demo-data`, used only to take the manual's screenshots of the activities with «Una entrega por»
 * (one submission per combination of an element with the values of other lists). Run after `app:load-demo-module-programs`, whose
 * teachers deliver here too. It builds, in 8.1:
 *
 * - «Revisión de la programación»: one per subject («Materia») and evaluation («Evaluación», associated with «Materia»: each subject
 *   only in its own), the evaluation marking the dates, delivered by filling the model «Revisión de la programación» in, with the
 *   subjects' teachers delivering. The 1ª is over and delivered in three subjects; the 2ª is open, with a draft of Programación.
 * - «Informe de evaluación del departamento»: the subjects gathered by head of department, one per department and evaluation it has
 *   subjects in (a file).
 * - «Acta trimestral del departamento»: no element, times the new list «Trimestre» (free: every department, every term).
 *
 * The dates of the 1ª and the 2ª are worked out from today, so the 1ª is always over and the 2ª always open when the screenshots are
 * taken; the rest are fixed.
 */
#[AsCommand(name: 'app:load-demo-evaluations', description: 'Crea las actividades con una entrega por materia y evaluación (para las capturas del manual)')]
final class LoadDemoEvaluationsCommand extends Command
{
    private const CENTRE_CODE = '29700456';

    /** @var array{cumplimiento: string, observaciones: string} the keys of the review model's fields */
    private array $reviewKeys = ['cumplimiento' => '', 'observaciones' => ''];

    /** @var array{table: string, valoracion: string, conclusiones: string} the keys of the report's model */
    private array $reportKeys = ['table' => '', 'valoracion' => '', 'conclusiones' => ''];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly ListItemRepository $items,
        private readonly TeacherRepository $teachers,
        private readonly FormModelService $models,
        private readonly SpecificProfileRepository $profiles,
        private readonly DocumentSectionRepository $sections,
        private readonly ActivityCategoryRepository $categories,
        private readonly ActivitySubmissionSlotBuilder $slotBuilder,
        private readonly FormSubmissionService $submissions,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $centre = $this->centres->findByCode(self::CENTRE_CODE);
        $author = $this->teachers->findByUsername('calidad');
        if ($centre === null || $author === null) {
            $io->error('Primero hay que cargar los datos de demostración (app:load-demo-data).');

            return Command::FAILURE;
        }

        $head = null;
        foreach ($this->profiles->findByCentre($centre) as $profile) {
            $head = $profile->getName() === 'Jefe/a de Departamento' ? $profile : $head;
        }
        $roots = [];
        foreach ($this->items->findRootsByCentre($centre) as $root) {
            $roots[$root->getName()] = $root;
        }
        $section = null;
        foreach ($this->sections->findAllByCentre($centre) as $candidate) {
            $section = $candidate->getName() === '8.1 Planificación y control operacional' ? $candidate : $section;
        }
        $subjects = $roots['Materia'] ?? null;
        $evals    = $roots['Evaluación'] ?? null;
        if (!$head instanceof SpecificProfile || !$subjects instanceof ListItem || !$evals instanceof ListItem || !$section instanceof DocumentSection) {
            $io->error('No se encuentran el perfil «Jefe/a de Departamento», las listas «Materia» y «Evaluación» o la sección 8.1 de los datos de demostración.');

            return Command::FAILURE;
        }

        $category = null;
        foreach ($this->categories->findAllByCentre($centre) as $candidate) {
            $category = $candidate->getName() === 'Sobre programaciones didácticas' ? $candidate : $category;
        }
        $category ??= (new ActivityCategory())->setEducationalCentre($centre)->setName('Sobre programaciones didácticas');
        $this->em->persist($category);

        // The 1ª is over (it ended ten days ago), the 2ª is open (until twenty days from now); the rest, fixed.
        $now   = $this->clock->now();
        $range = static fn (\DateTimeImmutable $from, \DateTimeImmutable $to): array => [(int) $from->format('j'), (int) $from->format('n'), (int) $to->format('j'), (int) $to->format('n')];
        $dates = [
            '1ª'      => $range($now->modify('-60 days'), $now->modify('-10 days')),
            '2ª'      => $range($now->modify('-5 days'), $now->modify('+20 days')),
            'Ord.'    => [1, 6, 20, 6],
            'Extr.'   => [1, 9, 10, 9],
            'Final 1' => [1, 3, 20, 3],
            'Final 2' => [1, 6, 25, 6],
        ];

        $review = $this->reviewActivity($centre, $category, $section, $head, $subjects, $evals, $dates, $author);
        $report = $this->reportActivity($category, $section, $head, $subjects, $evals, $dates, $review, $author);
        $this->minutesActivity($centre, $category, $section, $head);
        $this->em->flush();

        $this->startingState($review, $report);

        $io->success('Actividades con una entrega por materia y evaluación creadas: «Revisión de la programación» (1ª entregada en Programación, Bases de Datos y Matemáticas; 2ª en curso, con un borrador de Programación), «Informe de evaluación del departamento» (una por departamento y evaluación) y «Acta trimestral del departamento» (una por departamento y trimestre).');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array{int, int, int, int}> $dates
     */
    private function reviewActivity(EducationalCentre $centre, ActivityCategory $category, DocumentSection $section, SpecificProfile $head, ListItem $subjects, ListItem $evals, array $dates, \App\Entity\Teacher $author): Activity
    {
        $folder = (new Folder())->setDocumentSection($section)->setName('Revisiones de las programaciones')->setGroupByProfile(true);
        $folder->addUploadProfile($head);
        $modelsFolder = (new Folder())->setDocumentSection($section)->setName('Modelos de revisiones');
        $this->em->persist($folder);
        $this->em->persist($modelsFolder);
        $this->em->flush();

        $model            = $this->models->create($modelsFolder, 'Revisión de la programación', 'MD-09');
        $this->reviewKeys = ['cumplimiento' => FormDefinition::newKey(), 'observaciones' => FormDefinition::newKey()];
        $this->models->saveDraft($model, ['version' => 1, 'sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Seguimiento', 'numbered' => true, 'help' => '', 'children' => [],
            'blocks' => [
                ['key' => $this->reviewKeys['cumplimiento'], 'type' => 'field', 'name' => 'cumplimiento', 'fieldType' => 'number', 'label' => 'Grado de cumplimiento (%)', 'required' => true, 'help' => ''],
                ['key' => $this->reviewKeys['observaciones'], 'type' => 'field', 'name' => 'observaciones', 'fieldType' => 'long', 'label' => 'Observaciones', 'required' => false, 'help' => ''],
            ],
        ]]], $model->getDraftVersion(), $author);
        $this->models->sendToReview($model, $author, null);

        $activity = (new Activity())
            ->setCategory($category)
            ->setTitle('Revisión de la programación')
            ->setDescription('En cada evaluación, cada docente revisa la programación de sus materias: cuánto se ha cumplido y qué cambia.')
            ->setFolder($folder)
            ->setAutoComplete(true)
            ->setListItem($subjects)
            ->setFormModel($model)
            ->setTeachersDeliver(true)
            ->setDimensions([$evals], 0);
        $this->setDates($centre, $activity, $evals, $dates);
        $this->em->persist($activity);

        return $activity;
    }

    /**
     * One per department and evaluation, filled in with the model «Informe de evaluación del departamento», whose table «Revisión de
     * las materias» has a row per review of the department's subjects in that evaluation (their degree of compliance) and a column of
     * its own to assess each.
     *
     * @param array<string, array{int, int, int, int}> $dates
     */
    private function reportActivity(ActivityCategory $category, DocumentSection $section, SpecificProfile $head, ListItem $subjects, ListItem $evals, array $dates, Activity $review, \App\Entity\Teacher $author): Activity
    {
        $folder = (new Folder())->setDocumentSection($section)->setName('Informes de evaluación de los departamentos')->setGroupByProfile(true);
        $folder->addUploadProfile($head);
        $this->em->persist($folder);

        $reviewModel = $review->getFormModel() ?? throw new \LogicException('The review has no model.');
        $model       = $this->models->create($reviewModel->getDocument()->getFolder(), 'Informe de evaluación del departamento', 'MD-10');
        $table       = FormDefinition::newKey();
        $this->reportKeys = ['table' => $table, 'valoracion' => FormDefinition::newKey(), 'conclusiones' => FormDefinition::newKey()];
        $this->models->saveDraft($model, ['version' => 1, 'sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Materias del departamento', 'numbered' => true, 'help' => '', 'children' => [],
            'blocks' => [
                ['key' => $table, 'type' => 'table', 'mode' => 'delivered', 'label' => 'Revisión de las materias', 'sourceModel' => $reviewModel->getId()->toRfc4122(), 'sourceScope' => 'own', 'showStatus' => true, 'columns' => [
                    ['key' => FormDefinition::newKey(), 'label' => 'Cumplimiento (%)', 'fieldType' => 'number', 'sourceField' => 'cumplimiento', 'total' => 'avg'],
                    ['key' => $this->reportKeys['valoracion'], 'label' => 'Valoración del departamento', 'fieldType' => 'long'],
                ]],
                ['key' => $this->reportKeys['conclusiones'], 'type' => 'field', 'name' => 'conclusiones', 'fieldType' => 'long', 'label' => 'Conclusiones', 'required' => false, 'help' => ''],
            ],
        ]]], $model->getDraftVersion(), $author);
        $this->models->sendToReview($model, $author, null);

        $activity = (new Activity())
            ->setCategory($category)
            ->setTitle('Informe de evaluación del departamento')
            ->setDescription('Tras cada evaluación, cada jefatura de departamento informa de las materias de su departamento en esa evaluación.')
            ->setFolder($folder)
            ->setAutoComplete(true)
            ->setListItem($subjects)
            ->setSplitByElement(false)
            ->setFormModel($model)
            ->setDimensions([$evals], 0);
        $activity->setDeliverySource($table, $review);
        $this->setDates($category->getEducationalCentre(), $activity, $evals, $dates);
        $this->em->persist($activity);

        return $activity;
    }

    private function minutesActivity(EducationalCentre $centre, ActivityCategory $category, DocumentSection $section, SpecificProfile $head): void
    {
        $terms = (new ListItem())->setEducationalCentre($centre)->setName('Trimestre')->setPosition($this->items->nextRootPosition($centre));
        $this->em->persist($terms);
        $values = [];
        foreach (['1T' => [15, 9, 22, 12], '2T' => [8, 1, 31, 3], '3T' => [1, 4, 20, 6]] as $i => $range) {
            $term = (new ListItem())->setEducationalCentre($centre)->setName($i)->setPosition(\count($values))->setParent($terms);
            $this->em->persist($term);
            $values[$i] = [$term, $range];
        }

        $folder = (new Folder())->setDocumentSection($section)->setName('Actas de los departamentos')->setGroupByProfile(true);
        $folder->addUploadProfile($head);
        $this->em->persist($folder);

        $activity = (new Activity())
            ->setCategory($category)
            ->setTitle('Acta trimestral del departamento')
            ->setDescription('Cada departamento sube el acta de su reunión de cada trimestre.')
            ->setFolder($folder)
            ->setAutoComplete(true)
            ->setDimensions([$terms], 0);
        foreach ($values as [$term, [$sd, $sm, $ed, $em]]) {
            $activity->setDeadlineOverride($term, $sd, $sm, $ed, $em);
        }
        $envelope = $this->deadline->envelopeOf($centre, array_map(static fn (array $v): array => ['startDay' => $v[1][0], 'startMonth' => $v[1][1], 'endDay' => $v[1][2], 'endMonth' => $v[1][3]], array_values($values)));
        $activity->setStart($envelope['startDay'], $envelope['startMonth'])->setEnd($envelope['endDay'], $envelope['endMonth']);
        $this->em->persist($activity);
    }

    /**
     * Each evaluation's dates, and the activity's own: the span of them all.
     *
     * @param array<string, array{int, int, int, int}> $dates
     */
    private function setDates(EducationalCentre $centre, Activity $activity, ListItem $evals, array $dates): void
    {
        $ranges = [];
        foreach ($this->items->findLeafDescendants($evals) as $value) {
            [$sd, $sm, $ed, $em] = $dates[$value->getName()] ?? [1, 9, 30, 6];
            $activity->setDeadlineOverride($value, $sd, $sm, $ed, $em);
            $ranges[] = ['startDay' => $sd, 'startMonth' => $sm, 'endDay' => $ed, 'endMonth' => $em];
        }
        if ($ranges === []) {
            $activity->setStart(1, 9)->setEnd(30, 6);

            return;
        }
        $envelope = $this->deadline->envelopeOf($centre, $ranges);
        $activity->setStart($envelope['startDay'], $envelope['startMonth'])->setEnd($envelope['endDay'], $envelope['endMonth']);
    }

    /** The 1ª delivered in three subjects, and a draft of the 2ª of Programación — through the same services the forms use. */
    private function startingState(Activity $review, Activity $report): void
    {
        $compliance = $this->reviewKeys['cumplimiento'];
        $notes      = $this->reviewKeys['observaciones'];

        foreach ([
            ['Programación', '1ª', 'n.bravo', true, 90, 'Se ha cumplido lo previsto salvo la última unidad, que pasa a la 2ª evaluación.'],
            ['Bases de Datos', '1ª', 'n.bravo', true, 100, 'Sin cambios.'],
            ['Matemáticas', '1ª', 'p.sánchez', true, 80, 'Se refuerza el bloque de álgebra.'],
            ['Programación', '2ª', 'n.bravo', false, 40, ''],
        ] as [$subject, $evaluation, $username, $deliver, $percentage, $text]) {
            $teacher = $this->teachers->findByUsername($username);
            $slot    = null;
            foreach ($this->slotBuilder->buildSlots($review) as $candidate) {
                if ($candidate->nameListItem?->getName() === $subject && ($candidate->combination[0] ?? null)?->getName() === $evaluation) {
                    $slot = $candidate;
                }
            }
            if ($teacher === null || $slot === null) {
                continue;
            }
            $submission = $this->submissions->open($review, $slot);
            $this->submissions->save($submission, ['fields' => array_filter([$compliance => $percentage, $notes => $text], static fn ($v): bool => $v !== '')], $submission->getDraftVersion(), $teacher);
            if ($deliver) {
                $this->submissions->deliver($submission, $slot, $teacher);
            }
        }

        // The head of Matemáticas has delivered the department's report of the 1ª: its table, as read then, with the state of each
        // review and where they came from.
        $head = $this->teachers->findByUsername('p.sánchez');
        foreach ($this->slotBuilder->buildSlots($report) as $slot) {
            if ($head === null || $slot->listItem?->getName() !== 'Matemáticas' || ($slot->combination[0] ?? null)?->getName() !== '1ª') {
                continue;
            }
            $submission = $this->submissions->open($report, $slot);
            $read   = $this->submissions->refreshDelivered($submission, true)['data'];
            $source = FormDefinition::map((FormDefinition::map($read['sources'] ?? null) ?? [])[$this->reportKeys['table']] ?? null) ?? [];
            $rows   = [];
            foreach (FormDefinition::listOf($source['rows'] ?? []) as $row) {
                $delivered = ($row['status'] ?? '') === 'delivered';
                $rows[]    = ['_key' => FormDefinition::str($row['key'] ?? ''), $this->reportKeys['valoracion'] => $delivered ? 'Ritmo adecuado; se refuerza el álgebra.' : ''];
            }
            $this->submissions->save($submission, ['tables' => [$this->reportKeys['table'] => $rows], 'fields' => [$this->reportKeys['conclusiones'] => '<p>La 1ª evaluación se ha desarrollado según lo previsto.</p>']], $submission->getDraftVersion(), $head);
            $this->submissions->deliver($submission, $slot, $head);
        }
    }
}
