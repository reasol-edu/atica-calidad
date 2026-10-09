<?php

declare(strict_types=1);

namespace App\Model;

/** One activity in the compliance report (ActivityComplianceReportBuilder): the chosen year and, when asked, the one before it. */
final readonly class ActivityComplianceRow
{
    public function __construct(
        public string $categoryPath,
        public string $title,
        /** True for an activity completed by handing in documents; false for a manual one. */
        public bool $withSubmissions,
        public ActivityCycleFigures $current,
        public ?ActivityCycleFigures $previous,
    ) {}

    /** Change in the share done against the comparison year, in percentage points; null without one. */
    public function delta(): ?int
    {
        return $this->previous === null ? null : $this->current->percentage() - $this->previous->percentage();
    }
}
