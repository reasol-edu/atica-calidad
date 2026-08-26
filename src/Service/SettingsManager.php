<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\Teacher;
use App\Entity\TeacherSettingValue;
use App\Repository\CentreSettingValueRepository;
use App\Repository\GlobalSettingValueRepository;
use App\Repository\SettingDefinitionRepository;
use App\Repository\TeacherSettingValueRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves and persists settings rows for the admin UI (SettingsComponent),
 * covering the three scopes (global/centre/teacher) and their lock
 * inheritance (a locked global value overrides centre and teacher; a locked
 * centre value overrides teacher).
 */
final class SettingsManager
{
    public function __construct(
        private readonly SettingDefinitionRepository $definitions,
        private readonly GlobalSettingValueRepository $globalValues,
        private readonly CentreSettingValueRepository $centreValues,
        private readonly TeacherSettingValueRepository $teacherValues,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Returns rows for the given scope, each row containing the definition,
     * the stored raw value (null = not set), the stored file (only set for
     * pdf-typed settings that currently have one uploaded), lock state and
     * parent-lock origin.
     *
     * @return list<array{definition: SettingDefinition, storedValue: ?string, storedFile: ?SettingFile, effectiveValue: string, isLocked: bool, parentLock: ?string}>
     */
    public function getRows(string $scope, ?EducationalCentre $centre, ?Teacher $teacher): array
    {
        $defs          = $this->definitions->findByScope($scope);
        $storedMap     = $this->loadStoredMap($scope, $centre, $teacher);
        $parentLockMap = $this->loadParentLockMap($scope, $centre);
        $rows          = [];

        foreach ($defs as $def) {
            $key            = $def->getKey();
            $storedValue    = $storedMap[$key] ?? null;
            $stored         = $storedValue?->getValue();
            $parentLockInfo = $parentLockMap[$key] ?? null;

            $rows[] = [
                'definition'     => $def,
                'storedValue'    => $stored,
                'storedFile'     => $storedValue?->getFile(),
                'effectiveValue' => $parentLockInfo !== null
                                        ? $parentLockInfo['value']
                                        : ($stored ?? $def->getDefaultValue()),
                'isLocked'       => $storedValue !== null
                                        && method_exists($storedValue, 'isLocked')
                                        && $storedValue->isLocked(),
                'parentLock'     => $parentLockInfo !== null ? $parentLockInfo['origin'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Saves or resets a setting value for the given scope.
     * An empty string or '__default__' removes the stored value.
     */
    public function save(string $scope, string $key, string $value, ?EducationalCentre $centre, ?Teacher $teacher): SettingsSaveOutcome
    {
        if (isset($this->loadParentLockMap($scope, $centre)[$key])) {
            return SettingsSaveOutcome::RejectedLocked;
        }

        $isReset = $value === '' || $value === '__default__';
        $def     = $this->definitions->findOneBy(['key' => $key]);

        if ($def === null) {
            return SettingsSaveOutcome::RejectedInvalid;
        }

        if ($isReset) {
            $storedEntity = $this->loadStoredMap($scope, $centre, $teacher)[$key] ?? null;
            if ($storedEntity !== null && method_exists($storedEntity, 'isLocked') && $storedEntity->isLocked()) {
                return SettingsSaveOutcome::RejectedLocked;
            }

            $this->removeValue($scope, $def, $centre, $teacher);
        } else {
            if (!$def->isValueValid($value)) {
                return SettingsSaveOutcome::RejectedInvalid;
            }

            $this->upsertValue($scope, $def, $value, $centre, $teacher);
        }

        $this->em->flush();

        return SettingsSaveOutcome::Saved;
    }

    public function toggleLock(string $scope, string $key, ?EducationalCentre $centre): void
    {
        $def = $this->definitions->findOneBy(['key' => $key]);
        if ($def === null) {
            return;
        }

        match ($scope) {
            'global' => $this->toggleGlobalLock($def),
            'centre' => $this->toggleCentreLock($def, $centre),
            default  => null,
        };

        $this->em->flush();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function upsertValue(string $scope, SettingDefinition $def, string $value, ?EducationalCentre $centre, ?Teacher $teacher): void
    {
        match ($scope) {
            'global'  => $this->upsertGlobal($def, $value),
            'centre'  => $this->upsertCentre($def, $value, $centre),
            'teacher' => $this->upsertTeacher($def, $value, $teacher),
            default   => null,
        };
    }

    private function removeValue(string $scope, SettingDefinition $def, ?EducationalCentre $centre, ?Teacher $teacher): void
    {
        match ($scope) {
            'global'  => $this->removeGlobal($def),
            'centre'  => $this->removeCentre($def, $centre),
            'teacher' => $this->removeTeacher($def, $teacher),
            default   => null,
        };
    }

    private function upsertGlobal(SettingDefinition $def, string $value): void
    {
        $entity = $this->globalValues->findByDefinition($def)
            ?? (new GlobalSettingValue())->setDefinition($def);

        $entity->setValue($value);
        $this->em->persist($entity);
    }

    private function upsertCentre(SettingDefinition $def, string $value, ?EducationalCentre $centre): void
    {
        $centre = $this->requireCentre($centre);
        $entity = $this->centreValues->findByDefinitionAndCentre($def, $centre)
            ?? (new CentreSettingValue())->setDefinition($def)->setCentre($centre);

        $entity->setValue($value);
        $this->em->persist($entity);
    }

    private function upsertTeacher(SettingDefinition $def, string $value, ?Teacher $teacher): void
    {
        $teacher = $this->requireTeacher($teacher);
        $entity  = $this->teacherValues->findByDefinitionAndTeacher($def, $teacher)
            ?? (new TeacherSettingValue())->setDefinition($def)->setTeacher($teacher);

        $entity->setValue($value);
        $this->em->persist($entity);
    }

    private function removeGlobal(SettingDefinition $def): void
    {
        $entity = $this->globalValues->findByDefinition($def);
        if ($entity !== null) {
            $this->em->remove($entity);
        }
    }

    private function removeCentre(SettingDefinition $def, ?EducationalCentre $centre): void
    {
        $entity = $this->centreValues->findByDefinitionAndCentre($def, $this->requireCentre($centre));
        if ($entity !== null) {
            $this->em->remove($entity);
        }
    }

    private function removeTeacher(SettingDefinition $def, ?Teacher $teacher): void
    {
        $entity = $this->teacherValues->findByDefinitionAndTeacher($def, $this->requireTeacher($teacher));
        if ($entity !== null) {
            $this->em->remove($entity);
        }
    }

    private function toggleGlobalLock(SettingDefinition $def): void
    {
        $entity = $this->globalValues->findByDefinition($def);
        if ($entity === null) {
            $entity = (new GlobalSettingValue())
                ->setDefinition($def)
                ->setValue($def->getDefaultValue())
                ->setLocked(true);
            $this->em->persist($entity);
            return;
        }
        $entity->setLocked(!$entity->isLocked());
    }

    private function toggleCentreLock(SettingDefinition $def, ?EducationalCentre $centre): void
    {
        $centre = $this->requireCentre($centre);
        $entity = $this->centreValues->findByDefinitionAndCentre($def, $centre);
        if ($entity === null) {
            $entity = (new CentreSettingValue())
                ->setDefinition($def)
                ->setCentre($centre)
                ->setValue($def->getDefaultValue())
                ->setLocked(true);
            $this->em->persist($entity);
            return;
        }
        $entity->setLocked(!$entity->isLocked());
    }

    /**
     * Returns a map of keys locked by a parent scope.
     *
     * @return array<string, array{origin: 'global'|'centre', value: string}>
     */
    private function loadParentLockMap(string $scope, ?EducationalCentre $centre): array
    {
        $result = [];

        if (in_array($scope, ['centre', 'teacher'], true)) {
            foreach ($this->globalValues->findAllIndexedByKey() as $key => $v) {
                if ($v->isLocked()) {
                    $result[$key] = ['origin' => 'global', 'value' => $v->getValue()];
                }
            }
        }

        if ($scope === 'teacher' && $centre !== null) {
            foreach ($this->centreValues->findByCentreIndexedByKey($centre) as $key => $v) {
                if ($v->isLocked() && !isset($result[$key])) {
                    $result[$key] = ['origin' => 'centre', 'value' => $v->getValue()];
                }
            }
        }

        return $result;
    }

    /** @return array<string, GlobalSettingValue|CentreSettingValue|TeacherSettingValue> */
    private function loadStoredMap(string $scope, ?EducationalCentre $centre, ?Teacher $teacher): array
    {
        return match ($scope) {
            'global'  => $this->globalValues->findAllIndexedByKey(),
            'centre'  => $centre !== null ? $this->centreValues->findByCentreIndexedByKey($centre) : [],
            'teacher' => $teacher !== null ? $this->teacherValues->findByTeacherIndexedByKey($teacher) : [],
            default   => [],
        };
    }

    private function requireCentre(?EducationalCentre $centre): EducationalCentre
    {
        if ($centre === null) {
            throw new \LogicException('A centre is required for centre-scoped settings.');
        }

        return $centre;
    }

    private function requireTeacher(?Teacher $teacher): Teacher
    {
        if ($teacher === null) {
            throw new \LogicException('A teacher is required for teacher-scoped settings.');
        }

        return $teacher;
    }
}
