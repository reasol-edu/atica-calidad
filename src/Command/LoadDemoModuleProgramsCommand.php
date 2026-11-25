<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Repository\ActivityCategoryRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileRepository;
use App\Repository\TeacherRepository;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Service\Form\FormSubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Optional addition to `app:load-demo-data`, used only to take the manual's screenshots of the teachers associated with the elements of
 * the catalogue (Responsabilidades › Docentes asociados, and the activity's box «Los docentes asociados a cada elemento también lo
 * entregan»): the delivery of the module programmes, case 1 of the teachers' specification. It builds the folder «Programaciones de los
 * módulos» (next to «Programaciones didácticas», in 8.1, with the heads of department as the ones who upload), the model
 * «Programación didáctica del módulo» and the activity «Entrega de programaciones de los módulos», delivered by filling the model in
 * with the teachers' box checked over the «Materia» list (whose computing modules have their teachers from `app:load-demo-data`).
 *
 * Where it starts: «Programación» is delivered by Noelia (n.bravo) and waits for the review of Jefatura de Estudios; «Desarrollo Web en
 * Entorno Servidor» is a draft of Rocío (r.serrano); «Sistemas Informáticos» (nobody teaches it) and the FFEOE (its teacher is no longer
 * in the course) are the head of department's, Claudia (c.núñez). The model has no approval chain yet (that is the next phase): a
 * delivery waits for the folder's own review. Kept apart from `app:load-demo-form-models` on purpose, so the models and activities the
 * fillable-models chapter shows do not change; run it after `capture-modelos-shots.mjs` (see skills/screenshots.md).
 */
#[AsCommand(name: 'app:load-demo-module-programs', description: 'Crea la entrega de las programaciones de los módulos, con docentes asociados (para las capturas del manual)')]
final class LoadDemoModuleProgramsCommand extends Command
{
    private const CENTRE_CODE = '29700456';

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

        $jefeDpt = $jefeEstudios = null;
        foreach ($this->profiles->findByCentre($centre) as $profile) {
            $jefeDpt      = $profile->getName() === 'Jefe/a de Departamento' ? $profile : $jefeDpt;
            $jefeEstudios = $profile->getName() === 'Jefe/a de Estudios' ? $profile : $jefeEstudios;
        }
        $subjects = null;
        foreach ($this->items->findRootsByCentre($centre) as $root) {
            if ($root->getName() === 'Materia') {
                $subjects = $root;
            }
        }
        $section = null;
        foreach ($this->sections->findAllByCentre($centre) as $candidate) {
            if ($candidate->getName() === '8.1 Planificación y control operacional') {
                $section = $candidate;
            }
        }
        if (!$jefeDpt instanceof SpecificProfile || !$jefeEstudios instanceof SpecificProfile || !$subjects instanceof ListItem || !$section instanceof DocumentSection) {
            $io->error('No se encuentran los perfiles «Jefe/a de Departamento» y «Jefe/a de Estudios», la lista «Materia» o la sección 8.1 de los datos de demostración.');

            return Command::FAILURE;
        }

        $folder = (new Folder())->setDocumentSection($section)->setName('Programaciones de los módulos')->setGroupByProfile(true);
        $folder->addUploadProfile($jefeDpt);
        $folder->addResponsibleProfile($jefeEstudios);
        $folder->addReviewProfile($jefeEstudios);
        $modelsFolder = (new Folder())->setDocumentSection($section)->setName('Modelos de programaciones');
        $this->em->persist($folder);
        $this->em->persist($modelsFolder);
        $this->em->flush();

        $model = $this->models->create($modelsFolder, 'Programación didáctica del módulo', 'MD-08');
        $keys  = ['objectives' => FormDefinition::newKey(), 'timing' => FormDefinition::newKey(), 'assessment' => FormDefinition::newKey()];
        $this->models->saveDraft($model, ['version' => 1, 'sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Programación del módulo', 'numbered' => true, 'help' => '', 'children' => [],
            'blocks' => [
                ['key' => $keys['objectives'], 'type' => 'field', 'name' => 'objetivos', 'fieldType' => 'long', 'label' => 'Objetivos', 'required' => true, 'help' => ''],
                ['key' => $keys['timing'], 'type' => 'field', 'name' => 'temporalizacion', 'fieldType' => 'long', 'label' => 'Temporalización', 'required' => true, 'help' => ''],
                ['key' => $keys['assessment'], 'type' => 'field', 'name' => 'evaluacion', 'fieldType' => 'long', 'label' => 'Evaluación', 'required' => true, 'help' => ''],
            ],
        ]]], $model->getDraftVersion(), $author);
        $this->models->sendToReview($model, $author, null);

        $category = null;
        foreach ($this->categories->findAllByCentre($centre) as $candidate) {
            if ($candidate->getName() === 'Sobre programaciones didácticas') {
                $category = $candidate;
            }
        }
        $category ??= (new ActivityCategory())->setEducationalCentre($centre)->setName('Sobre programaciones didácticas');
        $activity   = (new Activity())
            ->setCategory($category)
            ->setTitle('Entrega de programaciones de los módulos')
            ->setDescription('Cada docente rellena y entrega la programación de sus módulos; la jefatura del departamento puede rellenar cualquiera de su departamento.')
            ->setStart(1, 9)
            ->setEnd(31, 12)
            ->setFolder($folder)
            ->setListItem($subjects)
            ->setFormModel($model)
            ->setTeachersDeliver(true);
        $this->em->persist($category);
        $this->em->persist($activity);
        $this->em->flush();

        // The starting state, through the same services the forms use.
        $text = static fn (string $what): array => ['fields' => [
            $keys['objectives'] => 'Que el alumnado ' . $what . '.',
            $keys['timing']     => 'Una unidad por semana a lo largo del primer trimestre.',
            $keys['assessment'] => 'Prácticas y una prueba escrita por unidad.',
        ]];
        foreach ([
            ['Programación', 'n.bravo', true, $text('domine los fundamentos de la programación')],
            ['Desarrollo Web en Entorno Servidor', 'r.serrano', false, ['fields' => [$keys['objectives'] => 'Que el alumnado construya aplicaciones web con un framework.']]],
        ] as [$module, $username, $deliver, $data]) {
            $teacher = $this->teachers->findByUsername($username);
            $slot    = null;
            foreach ($this->slotBuilder->buildSlots($activity) as $candidate) {
                if ($candidate->nameListItem?->getName() === $module) {
                    $slot = $candidate;
                }
            }
            if ($teacher === null || $slot === null) {
                continue;
            }
            $submission = $this->submissions->open($activity, $slot);
            $this->submissions->save($submission, $data, $submission->getDraftVersion(), $teacher);
            if ($deliver) {
                $this->submissions->deliver($submission, $slot, $teacher);
            }
        }

        $io->success('Entrega de las programaciones de los módulos creada: «Programación» entregada por n.bravo y «Desarrollo Web en Entorno Servidor» en borrador de r.serrano.');

        return Command::SUCCESS;
    }
}
