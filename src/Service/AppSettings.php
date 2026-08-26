<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\CentreSettingValueRepository;
use App\Repository\GlobalSettingValueRepository;
use App\Repository\SettingDefinitionRepository;
use App\Repository\TeacherSettingValueRepository;
use Symfony\Bundle\SecurityBundle\Security;

final class AppSettings implements AppSettingsInterface
{
    /** @var array<string, \App\Entity\SettingDefinition>|null shared base cache */
    private ?array $allDefinitions = null;

    /** @var array<string, \App\Entity\GlobalSettingValue>|null shared base cache */
    private ?array $globalMap = null;

    /** @var array<string, mixed>|null full resolved map for the current user / centre */
    private ?array $resolved = null;

    public function __construct(
        private readonly SettingDefinitionRepository   $definitions,
        private readonly GlobalSettingValueRepository  $globalValues,
        private readonly CentreSettingValueRepository  $centreValues,
        private readonly TeacherSettingValueRepository $teacherValues,
        private readonly TenantContextInterface $tenant,
        private readonly Security $security,
    ) {}

    public function get(string $key): mixed
    {
        $this->load();

        return $this->resolved[$key] ?? null;
    }

    public function getInt(string $key): int
    {
        $value = $this->get($key);

        return is_int($value) ? $value : 0;
    }

    public function getForTeacher(string $key, Teacher $teacher): mixed
    {
        $this->ensureBaseLoaded();

        $definition = $this->allDefinitions[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        $teacherMap = $this->teacherValues->findByTeacherIndexedByKey($teacher);

        $raw = match (true) {
            isset($this->globalMap[$key]) && $this->globalMap[$key]->isLocked()
                => $this->globalMap[$key]->getValue(),
            isset($teacherMap[$key])      => $teacherMap[$key]->getValue(),
            isset($this->globalMap[$key]) => $this->globalMap[$key]->getValue(),
            default                       => $definition->getDefaultValue(),
        };

        return match ($definition->getType()) {
            SettingType::Boolean => $raw === 'true',
            SettingType::Integer => (int) $raw,
            SettingType::String,
            SettingType::Choice,
            SettingType::RichText,
            SettingType::Pdf => $raw,
        };
    }

    public function getForCentre(string $key, EducationalCentre $centre): mixed
    {
        $this->ensureBaseLoaded();

        $definition = $this->allDefinitions[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        $centreMap = $this->centreValues->findByCentreIndexedByKey($centre);

        $raw = match (true) {
            isset($this->globalMap[$key]) && $this->globalMap[$key]->isLocked()
                => $this->globalMap[$key]->getValue(),
            isset($centreMap[$key])       => $centreMap[$key]->getValue(),
            isset($this->globalMap[$key]) => $this->globalMap[$key]->getValue(),
            default                       => $definition->getDefaultValue(),
        };

        return match ($definition->getType()) {
            SettingType::Boolean => $raw === 'true',
            SettingType::Integer => (int) $raw,
            SettingType::String,
            SettingType::Choice,
            SettingType::RichText,
            SettingType::Pdf => $raw,
        };
    }

    public function getGlobal(string $key): mixed
    {
        $this->ensureBaseLoaded();

        $definition = $this->allDefinitions[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        $raw = isset($this->globalMap[$key]) ? $this->globalMap[$key]->getValue() : $definition->getDefaultValue();

        return match ($definition->getType()) {
            SettingType::Boolean => $raw === 'true',
            SettingType::Integer => (int) $raw,
            SettingType::String,
            SettingType::Choice,
            SettingType::RichText,
            SettingType::Pdf => $raw,
        };
    }

    public function getForTeacherInCentre(string $key, Teacher $teacher, EducationalCentre $centre): mixed
    {
        $this->ensureBaseLoaded();

        $definition = $this->allDefinitions[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        $centreMap  = $this->centreValues->findByCentreIndexedByKey($centre);
        $teacherMap = $this->teacherValues->findByTeacherIndexedByKey($teacher);

        $raw = match (true) {
            isset($this->globalMap[$key]) && $this->globalMap[$key]->isLocked()
                => $this->globalMap[$key]->getValue(),
            isset($centreMap[$key]) && $centreMap[$key]->isLocked()
                => $centreMap[$key]->getValue(),
            isset($teacherMap[$key])      => $teacherMap[$key]->getValue(),
            isset($centreMap[$key])       => $centreMap[$key]->getValue(),
            isset($this->globalMap[$key]) => $this->globalMap[$key]->getValue(),
            default                       => $definition->getDefaultValue(),
        };

        return match ($definition->getType()) {
            SettingType::Boolean => $raw === 'true',
            SettingType::Integer => (int) $raw,
            SettingType::String,
            SettingType::Choice,
            SettingType::RichText,
            SettingType::Pdf => $raw,
        };
    }

    public function getFileForCentre(string $key, EducationalCentre $centre): ?ResolvedSettingFile
    {
        $this->ensureBaseLoaded();

        $definition = $this->allDefinitions[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        $centreMap = $this->centreValues->findByCentreIndexedByKey($centre);

        $winner = match (true) {
            isset($this->globalMap[$key]) && $this->globalMap[$key]->isLocked() => $this->globalMap[$key],
            isset($centreMap[$key])       => $centreMap[$key],
            isset($this->globalMap[$key]) => $this->globalMap[$key],
            default                       => null,
        };

        $file = $winner?->getFile();
        if ($file === null) {
            return null;
        }

        return new ResolvedSettingFile($file, $winner->getValue());
    }

    public function invalidate(): void
    {
        $this->resolved       = null;
        $this->allDefinitions = null;
        $this->globalMap      = null;
    }

    private function load(): void
    {
        if ($this->resolved !== null) {
            return;
        }

        $this->ensureBaseLoaded();

        $this->resolved = [];

        $centreMap  = $this->loadCentreMap();
        $teacherMap = $this->loadTeacherMap();

        foreach ($this->allDefinitions ?? [] as $key => $definition) {
            $raw = match (true) {
                isset($this->globalMap[$key]) && $this->globalMap[$key]->isLocked()
                    => $this->globalMap[$key]->getValue(),
                isset($centreMap[$key]) && $centreMap[$key]->isLocked()
                    => $centreMap[$key]->getValue(),
                isset($teacherMap[$key])      => $teacherMap[$key]->getValue(),
                isset($centreMap[$key])       => $centreMap[$key]->getValue(),
                isset($this->globalMap[$key]) => $this->globalMap[$key]->getValue(),
                default                       => $definition->getDefaultValue(),
            };

            $this->resolved[$key] = match ($definition->getType()) {
                SettingType::Boolean => $raw === 'true',
                SettingType::Integer => (int) $raw,
                SettingType::String,
                SettingType::Choice,
                SettingType::RichText,
                SettingType::Pdf => $raw,
            };
        }
    }

    private function ensureBaseLoaded(): void
    {
        if ($this->allDefinitions !== null) {
            return;
        }

        $this->allDefinitions = $this->definitions->findAllIndexedByKey();
        $this->globalMap      = $this->globalValues->findAllIndexedByKey();
    }

    /** @return array<string, \App\Entity\CentreSettingValue> */
    private function loadCentreMap(): array
    {
        $centre = $this->tenant->getSelectedCentre();

        return $centre !== null
            ? $this->centreValues->findByCentreIndexedByKey($centre)
            : [];
    }

    /** @return array<string, \App\Entity\TeacherSettingValue> */
    private function loadTeacherMap(): array
    {
        $user = $this->security->getUser();

        return $user instanceof Teacher
            ? $this->teacherValues->findByTeacherIndexedByKey($user)
            : [];
    }
}
