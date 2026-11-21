<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Service\AppSettingsInterface;
use App\Service\ResolvedSettingFile;

/** Global settings with the values a test gives; anything else is not what the test is about. */
final class FixedGlobalSettings implements AppSettingsInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = []) {}

    public function getGlobal(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function get(string $key): mixed
    {
        return $this->getGlobal($key);
    }

    public function getInt(string $key): int
    {
        return (int) $this->getGlobal($key);
    }

    public function getForTeacher(string $key, Teacher $teacher): mixed
    {
        return $this->getGlobal($key);
    }

    public function getForCentre(string $key, EducationalCentre $centre): mixed
    {
        return $this->getGlobal($key);
    }

    public function getFileForCentre(string $key, EducationalCentre $centre): ?ResolvedSettingFile
    {
        return null;
    }

    public function getForTeacherInCentre(string $key, Teacher $teacher, EducationalCentre $centre): mixed
    {
        return $this->getGlobal($key);
    }
}
