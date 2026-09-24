<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingKind;
use App\Entity\ImprovementAction;
use App\Repository\FindingRepository;
use App\Repository\ImprovementActionRepository;

/**
 * The code a finding gets when classified — its kind's prefix, the calendar year it's classified
 * in and the next number of that kind and year in its centre, NC-2026-014 — and the one an
 * improvement plan action gets when created, PM-2026-003. Three digits at least, more once past
 * 999. A clash between two at the very same moment is caught by the (centre, code) unique
 * constraints.
 */
final class FindingCodeGenerator
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly ImprovementActionRepository $actions,
    ) {}

    public function next(Finding $finding, FindingKind $kind, \DateTimeImmutable $at): string
    {
        $prefix = $kind->codePrefix() . '-' . $at->format('Y') . '-';

        return $prefix . $this->nextNumber($prefix, $this->findings->findCodesStartingWith($finding->getEducationalCentre(), $prefix));
    }

    public function nextPlanAction(EducationalCentre $centre, \DateTimeImmutable $at): string
    {
        $prefix = ImprovementAction::PLAN_CODE_PREFIX . '-' . $at->format('Y') . '-';

        return $prefix . $this->nextNumber($prefix, $this->actions->findCodesStartingWith($centre, $prefix));
    }

    /** @param list<string> $codes */
    private function nextNumber(string $prefix, array $codes): string
    {
        $max = 0;
        foreach ($codes as $code) {
            $number = substr($code, \strlen($prefix));
            if (ctype_digit($number)) {
                $max = max($max, (int) $number);
            }
        }

        return str_pad((string) ($max + 1), 3, '0', \STR_PAD_LEFT);
    }
}
