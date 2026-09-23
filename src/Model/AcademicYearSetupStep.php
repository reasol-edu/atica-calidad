<?php

declare(strict_types=1);

namespace App\Model;

/** One step of "Preparar el nuevo curso" (AcademicYearSetupChecklist). */
final readonly class AcademicYearSetupStep
{
    public function __construct(
        /** One of the AcademicYearSetupChecklist::STEP_* keys. */
        public string $key,
        public bool $done,
        /** What the step counts: teachers, non-working days, or stale assignments. */
        public int $count = 0,
        /** An earlier step has to be done first. */
        public bool $blocked = false,
    ) {}
}
