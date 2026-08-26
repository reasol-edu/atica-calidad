<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;

interface AppSettingsInterface
{
    /** Returns the resolved value for the current user / selected centre context. */
    public function get(string $key): mixed;

    /** Returns the resolved integer value for the current context. */
    public function getInt(string $key): int;

    /** Returns the resolved value for a specific teacher (teacher → global → default, no centre). */
    public function getForTeacher(string $key, Teacher $teacher): mixed;

    /** Returns the resolved value for a specific centre (centre → global → default, no teacher). */
    public function getForCentre(string $key, EducationalCentre $centre): mixed;

    /**
     * Returns the resolved file for a pdf-typed setting at a specific centre
     * (global-locked → centre → global → null; there is no file "default").
     */
    public function getFileForCentre(string $key, EducationalCentre $centre): ?ResolvedSettingFile;

    /** Returns the resolved value for a specific teacher within a specific centre (teacher → centre → global → default). */
    public function getForTeacherInCentre(string $key, Teacher $teacher, EducationalCentre $centre): mixed;

    /**
     * Returns the resolved value ignoring any centre or teacher context (global → default).
     * Safe to call outside an HTTP request (console commands, scheduled/queued message handlers).
     */
    public function getGlobal(string $key): mixed;
}
