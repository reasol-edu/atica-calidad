<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\ListItemRepository;
use App\Repository\TeacherRepository;
use App\Service\CentreProvisioner;
use App\Service\DocumentCreationService;
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
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
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
                    $politica->setName('Política de Calidad y Objetivos')->setDocumentSection($subclause);
                    $this->em->persist($politica);
                }
            }
        }

        $io->text(\sprintf('%d secciones ISO 9001:2015 creadas (7 capítulos, %d apartados).', $sections, $sections - 7));
        $io->text('Carpetas: "Programaciones didácticas" y "Planes de Acción Tutorial" en 8.1, "Actas del ETCP" (visible solo a equipo directivo y orientación) en 7.4, "Política de Calidad y Objetivos" en 5.2.');

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
