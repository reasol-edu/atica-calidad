<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingSeverity;
use App\Entity\Folder;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionType;
use App\Entity\Indicator;
use App\Entity\ListItem;
use App\Entity\Measurement;
use App\Entity\MeasurementCalendar;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\FindingRepository;
use App\Repository\ListItemRepository;
use App\Repository\TeacherRepository;
use App\Service\CentreProvisioner;
use App\Service\ActivityDeadlineChecker;
use App\Service\DocumentCreationService;
use App\Service\FindingService;
use App\Service\IndicatorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Populates a single, richly-connected demo educational centre ("IES Ada Lovelace" — ESO,
 * Bachillerato and FP) exercising every cross-cutting feature this app has: Responsabilidades
 * (lists with profile associations, specific profiles with/without list association), the
 * document tree (a full ISO 9001:2015 clause structure), and Actividades (a folder-linked
 * activity whose submissions are named from the "Materia" list, showing pending/approved/rejected
 * states). Refuses to run if a centre with the same code already exists, rather than guessing at
 * a safe way to wipe and recreate it — that's a destructive call this command doesn't get to make
 * on its own.
 */
#[AsCommand(name: 'app:load-demo-data')]
class LoadDemoDataCommand extends Command
{
    private const CENTRE_CODE = '29700456';
    private const CENTRE_NAME = 'IES Ada Lovelace';
    private const CENTRE_CITY = 'Málaga';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> username, password, first name, last name */
    private const NAMED_TEACHERS = [
        ['admin', 'admin', 'Admin', 'Global'],
        ['calidad', 'calidad', 'Laura', 'Jiménez Soto'],
        ['direccion', 'direccion', 'Javier', 'Morales Peña'],
    ];

    /** @var list<string> "Nombre Apellidos", password is always "prueba", username derived from the name */
    private const RANK_AND_FILE = [
        'Ana Ruiz Molina', 'Pablo Sánchez Vidal', 'Elena Torres Navarro', 'Javier Ramos Ortega',
        'Lucía Moreno Castro', 'Diego Herrera Blanco', 'Marta Iglesias Pardo', 'Álvaro Domínguez Vega',
        'Sara Cano Rubio', 'Hugo Delgado Serrano', 'Paula Vázquez Reyes', 'Adrián Gil Santos',
        'Claudia Núñez Aguilar', 'Sergio Marín Cortés', 'Irene Campos Lozano', 'Raúl Ibáñez Prieto',
        'Cristina Vidal Montes', 'Óscar Peña Cabrera', 'Beatriz Soto Fuentes', 'Fernando Crespo Bravo',
        'Silvia Méndez Carrasco', 'Rubén Guerrero Flores', 'Alba Rey Nieto', 'Iván Cortés Villar',
        'Noelia Bravo Escudero', 'Marcos Pardo Esteban', 'Rocío Serrano Vicente', 'Guillermo Ortiz Roldán',
        'Eva Cabrera Molina', 'Tomás Aguilar Reyes',
    ];

    /** @var array<string, Teacher> username => Teacher, filled as teachers are created */
    private array $teachers = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly ListItemRepository $items,
        private readonly TeacherRepository $teacherRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly CentreProvisioner $centreProvisioner,
        private readonly DocumentCreationService $documentCreation,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly FindingService $findingService,
        private readonly IndicatorService $indicatorService,
        private readonly FindingRepository $findingRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription($this->translator->trans('load_demo_data.description', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn (string $key, array $params = []) => $this->translator->trans($key, $params, 'command');

        if ($this->centres->findByCode(self::CENTRE_CODE) !== null) {
            $io->error($t('load_demo_data.error.existing', ['%name%' => self::CENTRE_NAME, '%code%' => self::CENTRE_CODE]));

            return Command::FAILURE;
        }

        // Teacher::username is unique across the whole app, not just this centre — checked
        // upfront so a collision fails cleanly here instead of surfacing mid-flush (both
        // CentreProvisioner::provision() and DocumentCreationService::storeFile() flush
        // internally, so a late failure would otherwise leave a half-built centre behind).
        $taken = array_filter(
            $this->plannedUsernames(),
            fn (string $username): bool => $this->teacherRepository->findByUsername($username) !== null,
        );
        if ($taken !== []) {
            $io->error($t('load_demo_data.error.usernames_taken', ['%usernames%' => implode(', ', $taken)]));

            return Command::FAILURE;
        }

        $year     = (int) $this->clock->now()->format('Y');
        $yearName = $year . '-' . ($year + 1);
        $centre   = $this->centreProvisioner->provision(self::CENTRE_CODE, self::CENTRE_NAME, self::CENTRE_CITY, $yearName);
        $academicYear = $centre->requireActiveAcademicYear();

        $io->section('Docentes');
        $this->createTeachers($centre, $academicYear, $io);

        $io->section('Listas de responsabilidades');
        [$departamentoLeaves, $grupoLeaves, $materiaLeaves, $materiaDepartamento] = $this->createLists($centre, $io);

        $io->section('Perfiles específicos');
        $profiles = $this->createProfiles($centre, $departamentoLeaves, $grupoLeaves, $io);

        $this->associateMaterias($profiles['Jefe/a de Departamento'], $departamentoLeaves, $materiaLeaves, $materiaDepartamento, $io);

        $io->section('Árbol documental (ISO 9001:2015)');
        $folders = $this->createDocumentTree($centre, $profiles, $io);

        $io->section('Actividades');
        $programacionesActivity = $this->createActivity($centre, $folders['programaciones'], $materiaLeaves, $io);
        $this->createIndividualActivity($centre, $folders['pat'], $io);
        $manualActivity = $this->createManualActivity($centre, $io);
        $this->createStatusShowcaseActivities($centre, $io);

        $io->section('Entregas de ejemplo');
        $this->seedSampleSubmissions($folders['programaciones'], $profiles, $departamentoLeaves, $materiaLeaves, $io);
        $this->seedPatSamples($folders['pat'], $profiles, $grupoLeaves, $io);
        $etcpDocument     = $this->seedEtcpSample($folders['etcp'], $io);
        $politicaDocument = $this->seedPoliticaSample($folders['politica'], $io);

        // Demo data for the activity "related documents" picker: a cross-reference to an
        // existing document elsewhere in the tree (the ETCP acta) and a standalone one created
        // just for this (the quality policy, which the manual activity's own description already
        // asks teachers to read).
        $programacionesActivity->addRelatedDocument($etcpDocument);
        $manualActivity->addRelatedDocument($politicaDocument);
        $io->text('Documentos relacionados: acta del ETCP enlazada a "Programaciones didácticas", política de calidad enlazada a "Lectura y conformidad con la Política de Calidad".');

        $io->section('Calendario');
        $this->createCalendarEvents($academicYear, $year, $io);

        $io->section('Mejora continua');
        $this->em->flush();
        $this->seedFindings($centre, $folders, $io);
        $this->seedImprovementPlan($centre, $folders, $io);
        $this->seedIndicators($centre, $folders, $io);

        $this->em->flush();

        $io->success($t('load_demo_data.success', ['%name%' => self::CENTRE_NAME, '%code%' => self::CENTRE_CODE, '%year%' => $yearName]));

        return Command::SUCCESS;
    }

    // ── Teachers ──────────────────────────────────────────────────────────────

    /** @return list<string> every username this command will try to create, in the order it will try them */
    private function plannedUsernames(): array
    {
        $usernames = array_map(static fn (array $row): string => $row[0], self::NAMED_TEACHERS);

        $seen = array_fill_keys($usernames, true);
        foreach (self::RANK_AND_FILE as $fullName) {
            [$first, $last] = explode(' ', $fullName, 2);
            $initial        = mb_strtolower(mb_substr($first, 0, 1));
            $lastFirst       = mb_strtolower(explode(' ', $last)[0]);
            $base            = $initial . '.' . $lastFirst;

            $username = $base;
            $suffix   = 2;
            while (isset($seen[$username])) {
                $username = $base . $suffix;
                ++$suffix;
            }
            $seen[$username] = true;
            $usernames[]      = $username;
        }

        return $usernames;
    }

    private function createTeachers(EducationalCentre $centre, AcademicYear $academicYear, SymfonyStyle $io): void
    {
        foreach (self::NAMED_TEACHERS as [$username, $password, $first, $last]) {
            $this->addTeacher($username, $password, $first, $last, $centre, $academicYear, admin: $username === 'admin');
        }

        foreach (self::RANK_AND_FILE as $fullName) {
            [$first, $last] = explode(' ', $fullName, 2);
            $username       = $this->usernameFor($first, $last);
            $this->addTeacher($username, 'prueba', $first, $last, $centre, $academicYear);
        }

        $io->text(\sprintf('%d docentes creados (admin, calidad, dirección + 30 docentes de plantilla).', count($this->teachers)));
    }

    private function addTeacher(
        string $username,
        string $password,
        string $firstName,
        string $lastName,
        EducationalCentre $centre,
        AcademicYear $academicYear,
        bool $admin = false,
    ): Teacher {
        $teacher = new Teacher(new PersonName($firstName, $lastName));
        $teacher->setUsername($username);
        $teacher->setPassword($this->passwordHasher->hashPassword($teacher, $password));
        $teacher->setAdmin($admin);
        $teacher->setForcePasswordChange(false);
        $academicYear->addTeacher($teacher);

        $this->em->persist($teacher);
        $this->teachers[$username] = $teacher;

        return $teacher;
    }

    private function usernameFor(string $firstName, string $lastName): string
    {
        $initial   = mb_strtolower(mb_substr($firstName, 0, 1));
        $lastFirst = mb_strtolower(explode(' ', $lastName)[0]);
        $base      = $initial . '.' . $lastFirst;

        $username = $base;
        $suffix   = 2;
        while (isset($this->teachers[$username])) {
            $username = $base . $suffix;
            ++$suffix;
        }

        return $username;
    }

    private function teacher(string $fullName): Teacher
    {
        [$first, $last] = explode(' ', $fullName, 2);
        $username        = $this->usernameForLookup($first, $last);

        return $this->teachers[$username] ?? throw new \LogicException(\sprintf('Unknown demo teacher "%s".', $fullName));
    }

    private function usernameForLookup(string $firstName, string $lastName): string
    {
        $initial   = mb_strtolower(mb_substr($firstName, 0, 1));
        $lastFirst = mb_strtolower(explode(' ', $lastName)[0]);

        return $initial . '.' . $lastFirst;
    }

    // ── Lists ─────────────────────────────────────────────────────────────────

    /**
     * @return array{0: array<string, ListItem>, 1: array<string, ListItem>, 2: array<string, ListItem>, 3: array<string, string>}
     *         name => leaf for Departamento/Grupo/Materia, plus materia name => owning departamento name
     */
    private function createLists(EducationalCentre $centre, SymfonyStyle $io): array
    {
        $departamentos = [
            'Matemáticas', 'Lengua Castellana y Literatura', 'Inglés', 'Física y Química',
            'Biología y Geología', 'Geografía e Historia', 'Educación Física', 'Tecnología', 'Informática',
        ];
        $departamentoLeaves = $this->createFlatList('Departamento', $departamentos, $centre);

        $grupos = [
            '1º ESO A', '1º ESO B', '2º ESO A', '2º ESO B', '3º ESO A', '3º ESO B', '4º ESO A',
            '1º Bach. A (Científico)', '1º Bach. B (Humanidades)', '2º Bach. A', '2º Bach. B',
            '1º DAM', '2º DAM', '1º DAW', '2º DAW',
        ];
        $grupoLeaves = $this->createFlatList('Grupo', $grupos, $centre);

        // Materia → its owning department; associated below, once "Jefe/a de Departamento" exists.
        $materias = [
            'Matemáticas' => 'Matemáticas',
            'Lengua Castellana y Literatura' => 'Lengua Castellana y Literatura',
            'Inglés' => 'Inglés',
            'Física y Química' => 'Física y Química',
            'Biología y Geología' => 'Biología y Geología',
            'Geografía e Historia' => 'Geografía e Historia',
            'Educación Física' => 'Educación Física',
            'Tecnología' => 'Tecnología',
            'Programación' => 'Informática',
            'Bases de Datos' => 'Informática',
            'Desarrollo Web en Entorno Cliente' => 'Informática',
            'Desarrollo Web en Entorno Servidor' => 'Informática',
            'Sistemas Informáticos' => 'Informática',
            'Fase de Formación en Empresa u Organismo Equiparado (FFEOE)' => 'Informática',
        ];
        $materiaLeaves = $this->createFlatList('Materia', array_keys($materias), $centre);

        $io->text(\sprintf(
            'Listas creadas: Departamento (%d), Grupo (%d), Materia (%d).',
            count($departamentoLeaves),
            count($grupoLeaves),
            count($materiaLeaves),
        ));

        return [$departamentoLeaves, $grupoLeaves, $materiaLeaves, $materias];
    }

    /**
     * @param array<string, ListItem> $departamentoLeaves
     * @param array<string, ListItem> $materiaLeaves
     * @param array<string, string>   $materiaDepartamento materia name => departamento name
     */
    private function associateMaterias(
        SpecificProfile $jefeDpt,
        array $departamentoLeaves,
        array $materiaLeaves,
        array $materiaDepartamento,
        SymfonyStyle $io,
    ): void {
        foreach ($materiaDepartamento as $materiaName => $departamentoName) {
            $materiaLeaves[$materiaName]->setAssociation($jefeDpt, $departamentoLeaves[$departamentoName]);
        }

        $io->text(\sprintf('%d materias asociadas a su jefatura de departamento.', count($materiaDepartamento)));
    }

    /**
     * Reuses the root CentreProvisioner already created for $rootName (see
     * responsibilities.lists.default_roots) instead of creating a duplicate — falls back to
     * creating one only if that translation was customized away from this demo data's expected
     * "Departamento;Grupo;Materia" names.
     *
     * @param  string[] $items
     * @return array<string, ListItem> name => leaf
     */
    private function createFlatList(string $rootName, array $items, EducationalCentre $centre): array
    {
        $root = $this->findRootByName($centre, $rootName);
        if ($root === null) {
            $root = new ListItem();
            $root->setName($rootName)->setEducationalCentre($centre)->setPosition($this->items->nextRootPosition($centre));
            $this->em->persist($root);
        }

        $leaves = [];
        foreach ($items as $i => $name) {
            $leaf = new ListItem();
            $leaf->setName($name)->setEducationalCentre($centre)->setPosition($i)->setParent($root);
            $this->em->persist($leaf);
            $leaves[$name] = $leaf;
        }

        return $leaves;
    }

    private function findRootByName(EducationalCentre $centre, string $name): ?ListItem
    {
        foreach ($this->items->findRootsByCentre($centre) as $root) {
            if ($root->getName() === $name) {
                return $root;
            }
        }

        return null;
    }

    // ── Profiles ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, ListItem> $departamentoLeaves
     * @param  array<string, ListItem> $grupoLeaves
     * @return array<string, SpecificProfile> name => profile
     */
    private function createProfiles(
        EducationalCentre $centre,
        array $departamentoLeaves,
        array $grupoLeaves,
        SymfonyStyle $io,
    ): array {
        $departamentoRoot = $this->firstLeaf($departamentoLeaves)->getParent() ?? throw new \LogicException('Departamento root missing.');
        $grupoRoot         = $this->firstLeaf($grupoLeaves)->getParent() ?? throw new \LogicException('Grupo root missing.');

        $calidad = $this->makeProfile('Responsable de calidad', $centre);
        $tutor   = $this->makeProfile('Tutor/a', $centre, $grupoRoot);
        $jefeDpt = $this->makeProfile('Jefe/a de Departamento', $centre, $departamentoRoot);
        $jefeEst = $this->makeProfile('Jefe/a de Estudios', $centre);
        $secret  = $this->makeProfile('Secretario/a', $centre);
        $director = $this->makeProfile('Director/a', $centre);
        $vicedir = $this->makeProfile('Vicedirector/a', $centre);
        $orient  = $this->makeProfile('Orientador/a', $centre);

        $calidad->addAssignment($this->teachers['calidad']);
        $centre->addQualityManager($this->teachers['calidad']);

        $director->addAssignment($this->teachers['direccion']);
        $centre->addAdmin($this->teachers['direccion']);

        $jefeEst->addAssignment($this->teacher('Guillermo Ortiz Roldán'));
        $secret->addAssignment($this->teacher('Eva Cabrera Molina'));
        $vicedir->addAssignment($this->teacher('Tomás Aguilar Reyes'));
        $orient->addAssignment($this->teacher('Paula Vázquez Reyes'));

        $departmentHeads = [
            'Matemáticas' => 'Pablo Sánchez Vidal',
            'Lengua Castellana y Literatura' => 'Elena Torres Navarro',
            'Inglés' => 'Marta Iglesias Pardo',
            'Física y Química' => 'Diego Herrera Blanco',
            'Biología y Geología' => 'Sara Cano Rubio',
            'Geografía e Historia' => 'Hugo Delgado Serrano',
            'Educación Física' => 'Raúl Ibáñez Prieto',
            'Tecnología' => 'Adrián Gil Santos',
            'Informática' => 'Claudia Núñez Aguilar',
        ];
        foreach ($departmentHeads as $departamento => $fullName) {
            $jefeDpt->addAssignment($this->teacher($fullName), $departamentoLeaves[$departamento]);
        }

        $tutors = [
            '1º ESO A' => 'Ana Ruiz Molina',
            '1º ESO B' => 'Sergio Marín Cortés',
            '2º ESO A' => 'Irene Campos Lozano',
            '2º ESO B' => 'Cristina Vidal Montes',
            '3º ESO A' => 'Óscar Peña Cabrera',
            '3º ESO B' => 'Beatriz Soto Fuentes',
            '4º ESO A' => 'Fernando Crespo Bravo',
            '1º Bach. A (Científico)' => 'Silvia Méndez Carrasco',
            '1º Bach. B (Humanidades)' => 'Rubén Guerrero Flores',
            '2º Bach. A' => 'Alba Rey Nieto',
            '2º Bach. B' => 'Iván Cortés Villar',
            '1º DAM' => 'Claudia Núñez Aguilar',
            '2º DAM' => 'Noelia Bravo Escudero',
            '1º DAW' => 'Marcos Pardo Esteban',
            '2º DAW' => 'Rocío Serrano Vicente',
        ];
        foreach ($tutors as $grupo => $fullName) {
            $tutor->addAssignment($this->teacher($fullName), $grupoLeaves[$grupo]);
        }

        $io->text('8 perfiles específicos creados y asignados.');

        return [
            'Responsable de calidad' => $calidad,
            'Tutor/a' => $tutor,
            'Jefe/a de Departamento' => $jefeDpt,
            'Jefe/a de Estudios' => $jefeEst,
            'Secretario/a' => $secret,
            'Director/a' => $director,
            'Vicedirector/a' => $vicedir,
            'Orientador/a' => $orient,
        ];
    }

    private function makeProfile(string $name, EducationalCentre $centre, ?ListItem $listRoot = null): SpecificProfile
    {
        $profile = new SpecificProfile();
        $profile->setName($name)->setEducationalCentre($centre);
        if ($listRoot !== null) {
            $profile->setListItem($listRoot);
        }
        $this->em->persist($profile);

        return $profile;
    }

    /** @param array<string, ListItem> $leaves */
    private function firstLeaf(array $leaves): ListItem
    {
        return $leaves[array_key_first($leaves)];
    }

    // ── Document tree ────────────────────────────────────────────────────────

    /**
     * @param  array<string, SpecificProfile> $profiles
     * @return array{programaciones: Folder, pat: Folder, etcp: Folder, politica: Folder}
     */
    private function createDocumentTree(EducationalCentre $centre, array $profiles, SymfonyStyle $io): array
    {
        $chapters = [
            '4. Contexto de la organización' => [
                '4.1 Comprensión de la organización y de su contexto',
                '4.2 Comprensión de las necesidades y expectativas de las partes interesadas',
                '4.3 Determinación del alcance del sistema de gestión de la calidad',
                '4.4 Sistema de gestión de la calidad y sus procesos',
            ],
            '5. Liderazgo' => [
                '5.1 Liderazgo y compromiso',
                '5.2 Política',
                '5.3 Roles, responsabilidades y autoridades en la organización',
            ],
            '6. Planificación' => [
                '6.1 Acciones para abordar riesgos y oportunidades',
                '6.2 Objetivos de la calidad y planificación para lograrlos',
                '6.3 Planificación de los cambios',
            ],
            '7. Apoyo' => [
                '7.1 Recursos',
                '7.2 Competencia',
                '7.3 Toma de conciencia',
                '7.4 Comunicación',
                '7.5 Información documentada',
            ],
            '8. Operación' => [
                '8.1 Planificación y control operacional',
                '8.2 Requisitos para los productos y servicios',
                '8.3 Diseño y desarrollo de los productos y servicios',
                '8.4 Control de los procesos, productos y servicios suministrados externamente',
                '8.5 Producción y provisión del servicio',
                '8.6 Liberación de los productos y servicios',
                '8.7 Control de las salidas no conformes',
            ],
            '9. Evaluación del desempeño' => [
                '9.1 Seguimiento, medición, análisis y evaluación',
                '9.2 Auditoría interna',
                '9.3 Revisión por la dirección',
            ],
            '10. Mejora' => [
                '10.1 Generalidades',
                '10.2 No conformidad y acción correctiva',
                '10.3 Mejora continua',
            ],
        ];

        $sections    = 0;
        $programaciones = null;
        $pat         = null;
        $etcp        = null;
        $politica    = null;
        $position    = 0;
        foreach ($chapters as $chapterName => $subclauses) {
            $chapter = new DocumentSection();
            $chapter->setName($chapterName)->setEducationalCentre($centre)->setPosition($position++);
            $this->em->persist($chapter);
            ++$sections;

            foreach ($subclauses as $i => $subclauseName) {
                $subclause = new DocumentSection();
                $subclause->setName($subclauseName)->setEducationalCentre($centre)->setPosition($i)->setParent($chapter);
                $this->em->persist($subclause);
                ++$sections;

                if ($subclauseName === '8.1 Planificación y control operacional') {
                    $programaciones = new Folder();
                    $programaciones->setName('Programaciones didácticas')
                        ->setDocumentSection($subclause)
                        ->setGroupByProfile(true);
                    $programaciones->addResponsibleProfile($profiles['Jefe/a de Estudios']);
                    $programaciones->addUploadProfile($profiles['Jefe/a de Departamento']);
                    $programaciones->addReviewProfile($profiles['Jefe/a de Estudios']);
                    $this->em->persist($programaciones);

                    // Coordinated by Orientación, not Jefatura de Estudios — a distinct
                    // responsible/reviewer from "Programaciones didácticas" in the same section.
                    $pat = new Folder();
                    $pat->setName('Planes de Acción Tutorial')
                        ->setDocumentSection($subclause)
                        ->setGroupByProfile(true);
                    $pat->addResponsibleProfile($profiles['Orientador/a']);
                    $pat->addUploadProfile($profiles['Tutor/a']);
                    $pat->addReviewProfile($profiles['Orientador/a']);
                    $this->em->persist($pat);
                }

                if ($subclauseName === '7.4 Comunicación') {
                    $etcp = new Folder();
                    $etcp->setName('Actas del ETCP')->setDocumentSection($subclause);
                    foreach (['Director/a', 'Vicedirector/a', 'Jefe/a de Estudios', 'Secretario/a', 'Orientador/a'] as $visibleTo) {
                        $etcp->addVisibilityProfile($profiles[$visibleTo]);
                    }
                    $this->em->persist($etcp);
                }

                if ($subclauseName === '5.2 Política') {
                    $politica = new Folder();
                    // Everyone has to confirm they've read the policy: the read-acknowledgement example.
                    $politica->setName('Política de Calidad y Objetivos')->setDocumentSection($subclause)->setRequiresReadAcknowledgement(true);
                    $this->em->persist($politica);
                }
            }
        }

        $io->text(\sprintf('%d secciones ISO 9001:2015 creadas (7 capítulos, %d apartados).', $sections, $sections - 7));
        $io->text('Carpetas: "Programaciones didácticas" y "Planes de Acción Tutorial" en 8.1, "Actas del ETCP" (visible solo a equipo directivo y orientación) en 7.4, "Política de Calidad y Objetivos" (con acuse de lectura) en 5.2.');

        return [
            'programaciones' => $programaciones ?? throw new \LogicException('Programaciones didácticas folder was not created.'),
            'pat' => $pat ?? throw new \LogicException('Planes de Acción Tutorial folder was not created.'),
            'etcp' => $etcp ?? throw new \LogicException('Actas del ETCP folder was not created.'),
            'politica' => $politica ?? throw new \LogicException('Política de Calidad folder was not created.'),
        ];
    }

    // ── Activity ──────────────────────────────────────────────────────────────

    /** @param array<string, ListItem> $materiaLeaves */
    private function createActivity(EducationalCentre $centre, Folder $folder, array $materiaLeaves, SymfonyStyle $io): Activity
    {
        $category = new ActivityCategory();
        $category->setName('Sobre programaciones didácticas')->setEducationalCentre($centre);
        $this->em->persist($category);

        $materiaRoot = $this->firstLeaf($materiaLeaves)->getParent() ?? throw new \LogicException('Materia root missing.');

        $activity = new Activity();
        $activity->setCategory($category)
            ->setTitle('Programaciones didácticas')
            ->setDescription('Sube la programación didáctica de tu materia en formato de fichero. Cada jefatura de departamento entrega la de las materias de su departamento.')
            ->setStart(1, 9)
            ->setEnd(30, 9)
            ->setFolder($folder)
            ->setListItem($materiaRoot)
            ->setRequired(true)
            ->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $activity->setAutoComplete(true);
        $this->em->persist($activity);

        $io->text('Categoría "Sobre programaciones didácticas" y actividad "Programaciones didácticas" creadas (autocompletado activo).');

        return $activity;
    }

    /** Individual scope: each tutor/a submits their own group's PAT, tracked separately even if two people ever share a group across the year. */
    private function createIndividualActivity(EducationalCentre $centre, Folder $folder, SymfonyStyle $io): void
    {
        $category = new ActivityCategory();
        $category->setName('Sobre planes de acción tutorial')->setEducationalCentre($centre);
        $this->em->persist($category);

        $activity = new Activity();
        $activity->setCategory($category)
            ->setTitle('Plan de Acción Tutorial (PAT)')
            ->setDescription('Cada tutor/a sube el Plan de Acción Tutorial de su grupo, coordinado por el Departamento de Orientación.')
            ->setStart(1, 10)
            ->setEnd(31, 10)
            ->setFolder($folder)
            ->setRequired(true)
            ->setSubmissionScope(ActivitySubmissionScope::Individual);
        $activity->setAutoComplete(true);
        $this->em->persist($activity);

        $io->text('Categoría "Sobre planes de acción tutorial" y actividad "Plan de Acción Tutorial (PAT)" creadas (ámbito individual, autocompletado activo).');
    }

    /** No folder: a personal reminder every teacher checks off manually — never auto-completable (see Activity::setAutoComplete()). */
    private function createManualActivity(EducationalCentre $centre, SymfonyStyle $io): Activity
    {
        $category = new ActivityCategory();
        $category->setName('Sensibilización y compromiso')->setEducationalCentre($centre);
        $this->em->persist($category);

        $activity = new Activity();
        $activity->setCategory($category)
            ->setTitle('Lectura y conformidad con la Política de Calidad')
            ->setDescription('Lee la Política de Calidad del centro y marca esta actividad como completada.')
            ->setStart(1, 9)
            ->setEnd(15, 9)
            ->setRequired(true);

        $this->em->persist($activity);

        $io->text('Categoría "Sensibilización y compromiso" y actividad manual "Lectura y conformidad con la Política de Calidad" creadas (sin carpeta).');

        return $activity;
    }

    /**
     * Four no-folder activities — one per colour state of the activity lists — so the demo shows
     * the status colouring straight away for every teacher: sin empezar / en plazo / vencida /
     * completada. Their windows are relative to "now" (day+month only, so, like every activity,
     * they repeat each course); near the turn of the year the "vencida" one may fall outside its
     * cycle and read as pending instead, an accepted quirk of the year-less deadline model.
     */
    private function createStatusShowcaseActivities(EducationalCentre $centre, SymfonyStyle $io): void
    {
        $category = new ActivityCategory();
        $category->setName('Seguimiento del SGC')->setEducationalCentre($centre);
        $this->em->persist($category);

        $now = $this->clock->now();
        /** @return array{0: int, 1: int} day, month of "now" shifted by $modifier */
        $dm = static function (string $modifier) use ($now): array {
            $d = $now->modify($modifier);

            return [(int) $d->format('j'), (int) $d->format('n')];
        };

        [$overdueStartD, $overdueStartM] = $dm('-2 months');
        [$overdueEndD, $overdueEndM]     = $dm('-3 weeks');
        $overdue = (new Activity())
            ->setCategory($category)
            ->setTitle('Revisión por la dirección (acta)')
            ->setDescription('Ejemplo de actividad cuyo plazo ya venció sin completar.')
            ->setStart($overdueStartD, $overdueStartM)
            ->setEnd($overdueEndD, $overdueEndM)
            ->setRequired(true);
        $this->em->persist($overdue);

        [$openStartD, $openStartM] = $dm('-1 week');
        [$openEndD, $openEndM]     = $dm('+3 weeks');
        $active = (new Activity())
            ->setCategory($category)
            ->setTitle('Encuesta de satisfacción del profesorado')
            ->setDescription('Ejemplo de actividad en plazo, todavía sin completar.')
            ->setStart($openStartD, $openStartM)
            ->setEnd($openEndD, $openEndM)
            ->setRequired(true);
        $this->em->persist($active);

        $completed = (new Activity())
            ->setCategory($category)
            ->setTitle('Difusión de los objetivos de calidad')
            ->setDescription('Ejemplo de actividad ya completada.')
            ->setStart($openStartD, $openStartM)
            ->setEnd($openEndD, $openEndM)
            ->setRequired(true);
        $this->em->persist($completed);
        foreach (['direccion', 'calidad', 'admin'] as $username) {
            $teacher = $this->teachers[$username];
            $this->em->persist(new ActivityCompletion($completed, $teacher, null, null, $teacher, $this->deadline->currentCycleKey($completed)));
        }

        [$futureStartD, $futureStartM] = $dm('+3 weeks');
        [$futureEndD, $futureEndM]     = $dm('+6 weeks');
        $notStarted = (new Activity())
            ->setCategory($category)
            ->setTitle('Auditoría interna (planificación)')
            ->setDescription('Ejemplo de actividad cuyo plazo aún no se ha abierto.')
            ->setStart($futureStartD, $futureStartM)
            ->setEnd($futureEndD, $futureEndM)
            ->setRequired(true);
        $this->em->persist($notStarted);

        $io->text('Categoría "Seguimiento del SGC" con cuatro actividades de ejemplo, una por estado (sin empezar, en plazo, vencida, completada).');
    }

    // ── Sample submissions ──────────────────────────────────────────────────────

    /**
     * @param array<string, SpecificProfile> $profiles
     * @param array<string, ListItem>        $departamentoLeaves
     * @param array<string, ListItem>        $materiaLeaves
     */
    private function seedSampleSubmissions(
        Folder $folder,
        array $profiles,
        array $departamentoLeaves,
        array $materiaLeaves,
        SymfonyStyle $io,
    ): void {
        $jefeDpt = $profiles['Jefe/a de Departamento'];
        $jefeEst = $this->teacher('Guillermo Ortiz Roldán');

        // Approved.
        $this->uploadSample($folder, $materiaLeaves['Matemáticas']->getName(), $jefeDpt, $departamentoLeaves['Matemáticas'], $this->teacher('Pablo Sánchez Vidal'), 'approved', $jefeEst);
        $this->uploadSample($folder, $materiaLeaves['Programación']->getName(), $jefeDpt, $departamentoLeaves['Informática'], $this->teacher('Claudia Núñez Aguilar'), 'approved', $jefeEst);

        // Pending review.
        $this->uploadSample($folder, $materiaLeaves['Bases de Datos']->getName(), $jefeDpt, $departamentoLeaves['Informática'], $this->teacher('Claudia Núñez Aguilar'), 'pending', null);

        // Rejected, with a review comment.
        $this->uploadSample(
            $folder,
            $materiaLeaves['Lengua Castellana y Literatura']->getName(),
            $jefeDpt,
            $departamentoLeaves['Lengua Castellana y Literatura'],
            $this->teacher('Elena Torres Navarro'),
            'rejected',
            $jefeEst,
            'Falta la programación de la evaluación inicial y la adaptación para el alumnado NEAE. Por favor, revisa y vuelve a subir.',
        );

        $io->text('4 entregas de ejemplo creadas (2 aprobadas, 1 pendiente, 1 rechazada) — el resto de materias quedan sin entregar para poder probar la subida.');
    }

    /**
     * @param array<string, SpecificProfile> $profiles
     * @param array<string, ListItem>        $grupoLeaves
     */
    private function seedPatSamples(Folder $folder, array $profiles, array $grupoLeaves, SymfonyStyle $io): void
    {
        $tutor  = $profiles['Tutor/a'];
        $orient = $this->teacher('Paula Vázquez Reyes');

        $this->uploadSample($folder, 'Tutor/a 1º ESO A', $tutor, $grupoLeaves['1º ESO A'], $this->teacher('Ana Ruiz Molina'), 'approved', $orient);
        $this->uploadSample($folder, 'Tutor/a 1º DAM', $tutor, $grupoLeaves['1º DAM'], $this->teacher('Claudia Núñez Aguilar'), 'pending', null);

        $io->text('2 PAT de ejemplo creados (1 aprobado, 1 pendiente) — el resto de grupos quedan sin entregar.');
    }

    private function seedEtcpSample(Folder $folder, SymfonyStyle $io): Document
    {
        $document = $this->uploadSample($folder, 'Acta ETCP nº1 — Inicio de curso', null, null, $this->teachers['direccion'], 'approved', null);

        $io->text('1 acta de ejemplo creada en "Actas del ETCP".');

        return $document;
    }

    /** Standalone reference document (no submission workflow) that the manual "Política de Calidad" activity links as a related document — demonstrates the feature with a document nothing else in the demo dataset already points to. */
    /**
     * "Mejora continua": one finding at each step, through FindingService and the real workflow
     * (run without a logged-in user, only its data rules apply) — a report still in the inbox, a
     * nonconformity being analysed, one whose actions are under way, an improvement opportunity
     * under way and a closed observation.
     *
     * @param array{programaciones: Folder, pat: Folder, etcp: Folder, politica: Folder} $folders
     */
    private function seedFindings(EducationalCentre $centre, array $folders, SymfonyStyle $io): void
    {
        $quality   = $this->teachers['calidad'];
        $direccion = $this->teachers['direccion'];
        $claudia   = $this->teacherNamed('Núñez Aguilar');
        $ana       = $this->teacherNamed('Ruiz Molina');
        $pablo     = $this->teacherNamed('Sánchez Vidal');
        $due       = fn (string $modify): \DateTimeImmutable => $this->clock->now()->setTime(0, 0)->modify($modify);

        // 1. Just reported: waiting in the quality manager's inbox.
        $this->findingService->report($centre, $claudia, "El proyector del aula 12 no funciona desde hace dos semanas\nSe dio parte a conserjería el día 3 y sigue sin arreglar; la clase de 2.º de Bachillerato no puede proyectar.", null);

        // 2. A nonconformity being analysed by Ana, with the repair already done.
        $late = $this->findingService->report($centre, $pablo, "Tres programaciones didácticas se han subido después del plazo\nEl departamento no sabía que el plazo acababa el 30 de octubre.", $folders['programaciones']->getDocumentSection());
        $this->findingService->classify($late, $quality, FindingKind::Nonconformity, FindingSeverity::Minor, 'Programaciones didácticas entregadas fuera de plazo', $folders['programaciones']->getDocumentSection(), FindingOrigin::InternalReport, $ana, $due('+10 days'));
        $this->findingService->addAction($centre, $late, $quality, ImprovementActionType::Repair, 'Recordar el plazo a los tres departamentos y recoger las programaciones pendientes', null, null, null, alreadyDone: true, result: 'Recogidas las tres programaciones.');
        $this->findingService->saveAnalysis($late, ['El departamento no conocía la fecha', 'La fecha solo se comunicó en el claustro de septiembre'], '');

        // 3. A nonconformity with its corrective actions under way.
        $policy = $this->findingService->report($centre, $direccion, "El profesorado nuevo no conoce la Política de Calidad\nEn la reunión de acogida nadie la había leído.", $folders['politica']->getDocumentSection());
        $this->findingService->classify($policy, $quality, FindingKind::Nonconformity, FindingSeverity::Major, 'La Política de Calidad no llega al profesorado de nueva incorporación', $folders['politica']->getDocumentSection(), FindingOrigin::InternalAudit, $quality, $due('+5 days'));
        $this->findingService->saveAnalysis($policy, ['Nadie se la entrega al llegar', 'El plan de acogida no la incluye'], 'El plan de acogida del profesorado no incluye la difusión de la Política de Calidad.');
        $this->findingService->addAction($centre, $policy, $quality, ImprovementActionType::Corrective, 'Incluir la Política de Calidad en el plan de acogida y activar el acuse de lectura en su carpeta', $quality, null, $due('+20 days'));
        $welcome = $this->findingService->addAction($centre, $policy, $quality, ImprovementActionType::Corrective, 'Presentar la Política de Calidad en la reunión de acogida', $direccion, null, $due('+30 days'));
        $this->findingService->submitAnalysis($policy, $quality);
        $this->findingService->startAction($welcome, $direccion);

        // 4. An improvement opportunity with its action.
        $agenda = $this->findingService->report($centre, $ana, 'Se podría enviar el orden del día de los claustros con una semana de antelación', null);
        $this->findingService->classify($agenda, $quality, FindingKind::ImprovementOpportunity, null, 'Orden del día de los claustros con una semana de antelación', null, FindingOrigin::InternalReport, null, null);
        $this->findingService->addAction($centre, $agenda, $quality, ImprovementActionType::Improvement, 'Publicar el orden del día en el calendario al convocar el claustro', $direccion, null, $due('+15 days'));

        // 5. A closed observation.
        $minutes = $this->findingService->report($centre, $claudia, 'Algunas actas de departamento se suben al árbol semanas después de la reunión', null);
        $this->findingService->classify($minutes, $quality, FindingKind::Observation, null, 'Actas de departamento subidas con retraso', null, FindingOrigin::InternalReport, null, null);
        $reminder = $this->findingService->addAction($centre, $minutes, $quality, ImprovementActionType::Preventive, 'Recordar en la CCP que las actas se suben en la semana de la reunión', $quality, null, $due('+7 days'));
        $this->findingService->completeAction($reminder, $quality, 'Recordado en la CCP de noviembre.');
        $this->findingService->close($minutes, $quality, 'Las actas del último mes se han subido a tiempo.');

        $io->text('5 fichas de ejemplo: una incidencia por clasificar, una no conformidad en análisis, otra con sus acciones en marcha, una oportunidad de mejora y una observación cerrada.');
    }

    /**
     * The active year's improvement plan: one action done, one under way and late, two pending.
     *
     * @param array{programaciones: Folder, pat: Folder, etcp: Folder, politica: Folder} $folders
     */
    private function seedImprovementPlan(EducationalCentre $centre, array $folders, SymfonyStyle $io): void
    {
        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            return;
        }
        $quality   = $this->teachers['calidad'];
        $direccion = $this->teachers['direccion'];
        $ana       = $this->teacherNamed('Ruiz Molina');
        $pablo     = $this->teacherNamed('Sánchez Vidal');
        $due       = fn (string $modify): \DateTimeImmutable => $this->clock->now()->setTime(0, 0)->modify($modify);
        $plan      = fn (ImprovementActionType $type, string $description, ?string $goal, ?DocumentSection $section, Teacher $responsible, string $when): ImprovementAction => $this->findingService->createPlanAction($centre, $year, $quality, $type, $description, $goal, $section, $responsible, null, $due($when));

        $minutes = $plan(ImprovementActionType::Improvement, 'Crear una plantilla común para las actas de departamento', 'Que todas las actas recojan los mismos apartados y se suban en la semana de la reunión.', null, $quality, '-10 days');
        $this->findingService->completeAction($minutes, $quality, 'Plantilla aprobada en la CCP y subida a la carpeta de actas.');

        $survey = $plan(ImprovementActionType::Improvement, 'Pasar una encuesta de satisfacción a las familias tras la primera evaluación', 'Conocer la valoración de las familias y compararla con la del curso pasado.', null, $pablo, '-3 days');
        $this->findingService->startAction($survey, $pablo);

        $plan(ImprovementActionType::Preventive, 'Revisar con cada departamento el calendario de entregas al comienzo de curso', 'Que ningún departamento desconozca los plazos de las programaciones.', $folders['programaciones']->getDocumentSection(), $ana, '+4 days');
        $plan(ImprovementActionType::Improvement, 'Preparar una guía de acogida para el profesorado de nueva incorporación', 'Que el profesorado nuevo conozca el sistema de calidad en su primera semana.', $folders['politica']->getDocumentSection(), $direccion, '+25 days');

        $io->text('4 acciones de ejemplo en el plan de mejora: una hecha, otra en curso y fuera de plazo, y dos pendientes.');
    }

    /**
     * Five indicators with a whole previous year of values ("2025-2026" before "2026-2027"), to
     * compare with and to see a full board, copied into the active year — whose "Evaluaciones"
     * starts with an "Evaluación inicial" just over: one indicator waits for Ana to record it, and
     * another came out off target, waiting for the quality manager to decide.
     *
     * @param array{programaciones: Folder, pat: Folder, etcp: Folder, politica: Folder} $folders
     */
    private function seedIndicators(EducationalCentre $centre, array $folders, SymfonyStyle $io): void
    {
        $year = $centre->getActiveAcademicYear();
        if ($year === null || preg_match('/(\d{4})/', $year->getName(), $m) !== 1) {
            return;
        }
        $first    = (int) $m[1];
        $previous = (new AcademicYear())->setName(($first - 1) . '-' . $first)->setEducationalCentre($centre);
        $this->em->persist($previous);
        $this->em->flush();

        $quality   = $this->teachers['calidad'];
        $direccion = $this->teachers['direccion'];
        $ana       = $this->teacherNamed('Ruiz Molina');
        $pablo     = $this->teacherNamed('Sánchez Vidal');

        $evaluations = $this->indicatorService->createCalendar($centre, $previous, 'Evaluaciones', 'evaluations');
        $terms       = $this->indicatorService->createCalendar($centre, $previous, 'Trimestral', 'terms');
        $annual      = $this->indicatorService->createCalendar($centre, $previous, 'Anual', 'year');

        $define = fn (string $name, string $how, ?DocumentSection $section, string $unit, bool $higher, Teacher $who, MeasurementCalendar $calendar, float $target, float $threshold): Indicator => $this->indicatorService->saveIndicator($centre, null, $previous, [
            'name' => $name, 'description' => $how, 'section' => $section, 'unit' => $unit, 'higherIsBetter' => $higher,
            'teacher' => $who, 'profile' => null, 'active' => true, 'calendar' => $calendar, 'target' => $target, 'alertThreshold' => $threshold,
        ]);
        $record = $this->recordValues(...);

        $programaciones = $folders['programaciones']->getDocumentSection();
        $approved = $define('Alumnado con todas las materias aprobadas', 'Alumnado con todas las materias aprobadas / alumnado evaluado × 100', $programaciones, '%', true, $ana, $evaluations, 60, 50);
        [$approvedFirst] = $record($approved, $evaluations, [48, 52, 58, 63, 66], $ana);
        $failing = $define('Alumnado con tres o más materias suspensas', 'Alumnado con tres o más materias suspensas / alumnado evaluado × 100', $programaciones, '%', false, $direccion, $evaluations, 15, 20);
        [$failingFirst] = $record($failing, $evaluations, [22, 19, 16, 13, 12], $direccion);
        // Last year's 1st evaluation, off target in both, was looked at in the evaluation sessions.
        foreach ([$approvedFirst, $failingFirst] as $offTarget) {
            $this->indicatorService->dismiss($offTarget, $quality, 'Se analizó en las sesiones de evaluación: los grupos recuperaron en la 2.ª.');
        }
        $absence = $define('Absentismo del alumnado', 'Faltas sin justificar / sesiones lectivas × 100', null, '%', false, $pablo, $terms, 5, 7);
        $absenceValues = $record($absence, $terms, [4.8, 6.2, 7.6], $pablo);
        $this->indicatorService->dismiss($absenceValues[2], $quality, 'Coincidió con la epidemia de gripe de marzo; se vigila en el curso siguiente.');
        $families = $define('Satisfacción de las familias', 'Media de la encuesta de satisfacción de las familias (de 1 a 10)', null, 'puntos', true, $quality, $annual, 7.5, 7);
        $record($families, $annual, [7.8], $quality);
        $delivered = $define('Programaciones entregadas en plazo', 'Programaciones subidas antes de la fecha límite / programaciones esperadas × 100', $programaciones, '%', true, $quality, $annual, 100, 95);
        [$late] = $record($delivered, $annual, [88], $quality);
        // That value is what the demo nonconformity about late programaciones answers to.
        $finding = $this->findingRepository->findOneBy(['educationalCentre' => $centre, 'title' => 'Programaciones didácticas entregadas fuera de plazo']);
        if ($finding instanceof Finding) {
            $finding->setOrigin(FindingOrigin::Indicator)->setMeasurement($late);
            $late->markReviewed($quality, $this->clock->now());
            $this->em->flush();
        }

        // This year: the same, plus an "Evaluación inicial" just over.
        $this->indicatorService->copyYear($centre, $previous, $year);
        $current = $failing->targetFor($year)?->getCalendar();
        if ($current !== null) {
            $today = $this->clock->now()->setTime(0, 0);
            $this->indicatorService->saveCalendar($current, $current->getName(), [
                ['id' => null, 'name' => 'Evaluación inicial', 'start' => $today->modify('-30 days'), 'end' => $today->modify('-3 days')],
                ...array_map(static fn ($p): array => ['id' => $p->getId()->toRfc4122(), 'name' => $p->getName(), 'start' => $p->getStartDate(), 'end' => $p->getEndDate()], $current->getPeriods()->toArray()),
            ]);
            foreach ($current->getPeriods() as $period) {
                if ($period->getName() === 'Evaluación inicial') {
                    $this->indicatorService->record($failing, $period, 24, 'Dato de las sesiones de evaluación inicial.', $direccion);
                }
            }
        }

        $io->text('5 indicadores de ejemplo, con los valores del curso ' . $previous->getName() . ' y la evaluación inicial de este: uno por registrar y otro fuera de meta.');
    }

    /**
     * Records $values for $calendar's periods, in order.
     *
     * @param list<float> $values
     *
     * @return list<Measurement>
     */
    private function recordValues(Indicator $indicator, MeasurementCalendar $calendar, array $values, Teacher $who): array
    {
        $recorded = [];
        foreach (array_values($calendar->getPeriods()->toArray()) as $i => $period) {
            if (isset($values[$i])) {
                $recorded[] = $this->indicatorService->record($indicator, $period, $values[$i], null, $who);
            }
        }

        return $recorded;
    }

    private function teacherNamed(string $lastName): Teacher
    {
        foreach ($this->teachers as $teacher) {
            if ($teacher->getName()->getLastName() === $lastName) {
                return $teacher;
            }
        }

        throw new \LogicException(\sprintf('No demo teacher named "%s".', $lastName));
    }

    private function seedPoliticaSample(Folder $folder, SymfonyStyle $io): Document
    {
        $document = $this->uploadSample($folder, 'Política de Calidad y Objetivos 2025-2026', null, null, $this->teachers['direccion'], 'approved', $this->teachers['calidad']);

        $io->text('1 documento de ejemplo creado en "Política de Calidad y Objetivos".');

        return $document;
    }

    private function uploadSample(
        Folder $folder,
        string $name,
        ?SpecificProfile $profile,
        ?ListItem $listItem,
        Teacher $uploader,
        string $state,
        ?Teacher $reviewer,
        ?string $reviewResult = null,
    ): Document {
        $content = "{$name} — documento de demostración.";
        $path    = tempnam(sys_get_temp_dir(), 'demo_doc_');
        file_put_contents($path, $content);
        $file = new UploadedFile($path, $name . '.pdf', 'application/pdf', null, true);

        $document = $this->documentCreation->createWithFirstRevision($folder, $name, $profile, $listItem, $file, $uploader);

        if ($state === 'approved' && $reviewer !== null) {
            $revision = $document->getPendingRevision();
            if ($revision !== null) {
                $revision->approve($reviewer, $reviewResult);
                $document->setActiveRevision($revision);
            }
        } elseif ($state === 'rejected' && $reviewer !== null) {
            $revision = $document->getPendingRevision();
            $revision?->reject($reviewer, $reviewResult);
        }

        @unlink($path);

        return $document;
    }

    // ── Calendar ─────────────────────────────────────────────────────────────

    private function createCalendarEvents(AcademicYear $academicYear, int $startYear, SymfonyStyle $io): void
    {
        $events = [
            ['Evaluación inicial', $startYear, 10, 15, '16:00', '18:00'],
            ['1ª Evaluación', $startYear, 12, 12, '16:00', '19:00'],
            ['2ª Evaluación', $startYear + 1, 3, 13, '16:00', '19:00'],
            ['3ª Evaluación / Evaluación final', $startYear + 1, 6, 12, '16:00', '19:00'],
        ];

        foreach ($events as [$name, $y, $m, $d, $start, $end]) {
            $event = new SchoolEvent();
            $event->setAcademicYear($academicYear)
                ->setName($name)
                ->setDescription('Sesión de evaluación de todos los grupos.')
                ->setDate(new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $y, $m, $d)))
                ->setStartTime(new \DateTimeImmutable($start))
                ->setEndTime(new \DateTimeImmutable($end))
                ->setGeneral(true);
            $this->em->persist($event);
        }

        $io->text(\sprintf('%d sesiones de evaluación creadas en el calendario.', count($events)));
    }
}
