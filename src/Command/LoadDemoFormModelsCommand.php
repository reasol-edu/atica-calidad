<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Repository\EducationalCentreRepository;
use App\Repository\ListItemRepository;
use App\Repository\TeacherRepository;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Optional addition to `app:load-demo-data`, used only to take the manual's screenshots of the fillable document models: a model
 * «Memoria de departamento» in force (a choice field and a table column fed by the «Departamento» list of the demo centre), and an
 * activity «Memoria final de departamento» that is delivered by filling it in online. It delivers nothing: the capture script fills
 * the form as the quality manager (`calidad`, who is the profile's teacher) in the browser. Kept apart from the main demo data on
 * purpose: those counts and names are quoted in the captions of every other chapter.
 */
#[AsCommand(name: 'app:load-demo-form-models', description: 'Crea un modelo de documento rellenable y su actividad en el centro de demostración (para las capturas del manual)')]
final class LoadDemoFormModelsCommand extends Command
{
    private const CENTRE_CODE = '29700456';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly ListItemRepository $items,
        private readonly TeacherRepository $teachers,
        private readonly FormModelService $models,
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
        $departments = null;
        foreach ($this->items->findRootsByCentre($centre) as $root) {
            if ($root->getName() === 'Departamento') {
                $departments = $root;
            }
        }
        if (!$departments instanceof ListItem) {
            $io->error('No se encuentra la lista «Departamento».');

            return Command::FAILURE;
        }

        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Memorias');
        $models  = (new Folder())->setDocumentSection($section)->setName('Modelos rellenables');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Memorias de departamento');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable de memorias');
        $folder->addUploadProfile($profile);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Memorias finales');
        foreach ([$section, $models, $folder, $profile, $category, new SpecificProfileAssignment($profile, null, $author)] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $model = $this->models->create($models, 'Memoria de departamento', 'MD-07');
        $this->models->saveDraft($model, $this->definition($departments->getId()->toRfc4122()), $model->getDraftVersion(), $author);
        $this->models->sendToReview($model, $author, null);

        $activity = (new Activity())
            ->setCategory($category)
            ->setTitle('Memoria final de departamento')
            ->setDescription('Cada departamento cuenta cómo ha ido el curso: reuniones celebradas y acuerdos. Se rellena en línea.')
            ->setStart(1, 9)
            ->setEnd(30, 6)
            ->setFolder($folder)
            ->setFormModel($model);
        $this->em->persist($activity);
        $this->em->flush();

        $io->success('Modelo «Memoria de departamento» y actividad «Memoria final de departamento» creados (se rellena como «calidad»).');

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function definition(string $departmentsId): array
    {
        return ['version' => 1, 'sections' => [
            [
                'key' => FormDefinition::newKey(), 'title' => 'Datos del departamento', 'numbered' => true, 'help' => '', 'children' => [],
                'blocks' => [
                    ['key' => FormDefinition::newKey(), 'type' => 'field', 'name' => 'departamento', 'fieldType' => 'choice', 'label' => 'Departamento', 'required' => true, 'options' => [], 'list' => $departmentsId, 'help' => 'Las opciones salen de la lista «Departamento» de Responsabilidades.'],
                    ['key' => FormDefinition::newKey(), 'type' => 'field', 'name' => 'reuniones', 'fieldType' => 'number', 'label' => 'Reuniones celebradas', 'required' => true, 'min' => 0, 'unit' => '', 'help' => ''],
                    ['key' => FormDefinition::newKey(), 'type' => 'field', 'name' => 'valoracion', 'fieldType' => 'long', 'label' => 'Valoración del curso', 'required' => false, 'help' => ''],
                ],
            ],
            [
                'key' => FormDefinition::newKey(), 'title' => 'Reuniones con otros departamentos', 'numbered' => true, 'help' => '', 'children' => [],
                'blocks' => [
                    ['key' => FormDefinition::newKey(), 'type' => 'table', 'name' => 'coordinacion', 'label' => 'Coordinación', 'mode' => 'free', 'rowHeader' => '', 'rows' => [], 'minRows' => 0, 'maxRows' => 8, 'help' => '', 'columns' => [
                        ['key' => FormDefinition::newKey(), 'name' => 'asunto', 'label' => 'Asunto', 'fieldType' => 'short', 'required' => false],
                        ['key' => FormDefinition::newKey(), 'name' => 'departamentos', 'label' => 'Departamentos implicados', 'fieldType' => 'multichoice', 'required' => false, 'options' => [], 'list' => $departmentsId],
                    ]],
                ],
            ],
        ]];
    }
}
