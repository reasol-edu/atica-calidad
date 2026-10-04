<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importa el listado de docentes del centro exportado por Séneca (CSV) al curso activo.
 *
 * El proceso tiene tres pasos para poder mostrar una vista previa: {@see parse()} lee el fichero,
 * {@see plan()} calcula qué haría con cada fila y {@see apply()} lo ejecuta solo para los docentes
 * seleccionados. Opcionalmente rellena el correo electrónico (columna «Cuenta Google/Microsoft») de
 * quienes no lo tienen y retira del curso a quienes no figuran en el fichero ni tienen ninguna
 * responsabilidad vigente.
 */
final class CentreTeacherImporter
{
    public const COLUMN_NAME     = 'Empleado/a';
    public const COLUMN_USERNAME = 'Usuario IdEA';
    public const COLUMN_EMAIL    = 'Cuenta Google/Microsoft';

    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly EntityManagerInterface $em,
        private readonly CsvReader $csvReader,
    ) {}

    /**
     * Lee el CSV (UTF-8 o Windows-1252). Las filas sin usuario IdEA o sin «Apellidos, Nombre» se omiten.
     *
     * @return array{rows: list<array{username: string, firstName: string, lastName: string, email: ?string}>, skipped: int}
     * @throws \InvalidArgumentException con el mensaje «empty» (sin cabeceras) o «missing:<columna>»
     */
    public function parse(string $content): array
    {
        $parsed = $this->csvReader->parse($content);
        if ($parsed['headers'] === []) {
            throw new \InvalidArgumentException('empty');
        }

        $missing = $this->csvReader->findMissingColumn($parsed['headers'], [self::COLUMN_NAME, self::COLUMN_USERNAME]);
        if ($missing !== null) {
            throw new \InvalidArgumentException('missing:' . $missing);
        }

        $rows    = [];
        $skipped = 0;
        foreach ($parsed['rows'] as $row) {
            $username  = $row[self::COLUMN_USERNAME] ?? '';
            $nameParts = explode(', ', $row[self::COLUMN_NAME] ?? '', 2);
            $lastName  = $nameParts[0];
            $firstName = $nameParts[1] ?? '';

            if ($username === '' || $firstName === '' || $lastName === '') {
                ++$skipped;
                continue;
            }

            $email = $row[self::COLUMN_EMAIL] ?? '';
            $rows[$username] = [   // un usuario repetido en el fichero cuenta una sola vez (gana la última fila)
                'username'  => $username,
                'firstName' => $firstName,
                'lastName'  => $lastName,
                'email'     => $email !== '' && mb_strlen($email) <= 180 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            ];
        }

        return ['rows' => array_values($rows), 'skipped' => $skipped];
    }

    /**
     * @param list<array{username: string, firstName: string, lastName: string, email: ?string}> $parsedRows
     * @return list<TeacherImportRow>
     */
    public function plan(AcademicYear $year, array $parsedRows): array
    {
        $claimed = [];
        $plan    = [];
        foreach ($parsedRows as $row) {
            $teacher = $this->teachers->findByUsername($row['username']);
            $status  = match (true) {
                $teacher === null                         => TeacherImportRow::NEW,
                !$year->getTeachers()->contains($teacher) => TeacherImportRow::ADD,
                default                                   => TeacherImportRow::IN_YEAR,
            };

            // Un correo ya usado por otro docente (o repetido en el propio fichero) no se importa.
            $conflict = false;
            if ($row['email'] !== null) {
                $key      = mb_strtolower($row['email']);
                $conflict = isset($claimed[$key]) || $this->teachers->isEmailTakenByAnother($row['email'], $teacher);
                $claimed[$key] = true;
            }

            $plan[] = new TeacherImportRow($row['username'], $row['firstName'], $row['lastName'], $row['email'], $status, $teacher, $conflict);
        }

        return $plan;
    }

    /**
     * Docentes del curso que no figuran en el fichero y no tienen ninguna responsabilidad vigente
     * (ver {@see TeacherRepository::findConnectedIdsForYear()}). Son los candidatos a retirar del curso.
     *
     * @param list<TeacherImportRow> $plan
     * @return list<Teacher>
     */
    public function findRemovalCandidates(AcademicYear $year, array $plan, ?Teacher $except = null): array
    {
        $inFile = [];
        foreach ($plan as $row) {
            $inFile[$row->username] = true;
        }
        $connected = $this->teachers->findConnectedIdsForYear($year);

        $candidates = [];
        foreach ($year->getTeachers() as $teacher) {
            if (isset($inFile[$teacher->getUsername()]) || isset($connected[$teacher->getId()->toRfc4122()])) {
                continue;
            }
            if ($except !== null && $except->getId()->equals($teacher->getId())) {
                continue;
            }
            $candidates[] = $teacher;
        }
        usort($candidates, static fn (Teacher $a, Teacher $b): int =>
            [$a->getName()->getLastName(), $a->getName()->getFirstName()] <=> [$b->getName()->getLastName(), $b->getName()->getFirstName()]);

        return $candidates;
    }

    /**
     * Aplica la importación solo a los docentes seleccionados. No hace flush.
     *
     * @param list<TeacherImportRow> $plan
     * @param list<string>           $selectedUsernames docentes a crear, añadir o actualizar
     * @param list<string>           $removeTeacherIds  ids (RFC 4122) de los docentes a retirar del curso
     * @return array{created: int, added: int, emails: int, removed: int}
     */
    public function apply(
        AcademicYear $year,
        array $plan,
        array $selectedUsernames,
        bool $importEmail,
        bool $removeMissing,
        array $removeTeacherIds,
        ?Teacher $except = null,
    ): array {
        $selected = array_flip($selectedUsernames);
        $summary  = ['created' => 0, 'added' => 0, 'emails' => 0, 'removed' => 0];

        // Los candidatos a retirar se calculan antes de crear nada y siempre se revalidan aquí:
        // el servidor no se fía de los ids que llegan del formulario.
        $candidates = $removeMissing ? $this->findRemovalCandidates($year, $plan, $except) : [];

        foreach ($plan as $row) {
            if (!isset($selected[$row->username])) {
                continue;
            }

            $teacher = $row->teacher;
            if ($row->status === TeacherImportRow::NEW) {
                $teacher = (new Teacher(new PersonName($row->firstName, $row->lastName)))
                    ->setUsername($row->username)
                    ->setExternal(true)
                    ->setActive(true);
                $this->em->persist($teacher);
                $year->addTeacher($teacher);
                ++$summary['created'];
            } elseif ($row->status === TeacherImportRow::ADD && $teacher !== null) {
                $year->addTeacher($teacher);
                ++$summary['added'];
            }

            // Solo se rellena el correo que falta (nunca se pisa uno existente) y que nadie más usa.
            if ($importEmail && $teacher !== null && $row->fillsEmail()) {
                $teacher->setEmail($row->email);
                ++$summary['emails'];
            }
        }

        $toRemove = array_flip($removeTeacherIds);
        foreach ($candidates as $teacher) {
            if (isset($toRemove[$teacher->getId()->toRfc4122()])) {
                $year->removeTeacher($teacher);
                ++$summary['removed'];
            }
        }

        return $summary;
    }
}
