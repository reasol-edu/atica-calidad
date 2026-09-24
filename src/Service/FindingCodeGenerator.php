<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Finding;
use App\Entity\FindingKind;
use App\Repository\FindingRepository;

/**
 * The code a finding gets when classified: its kind's prefix, the calendar year it's classified
 * in and the next number of that kind and year in its centre — NC-2026-014. Three digits at least,
 * more once past 999. A clash between two classifications at the very same moment is caught by the
 * (centre, code) unique constraint.
 */
final class FindingCodeGenerator
{
    public function __construct(
        private readonly FindingRepository $findings,
    ) {}

    public function next(Finding $finding, FindingKind $kind, \DateTimeImmutable $at): string
    {
        $prefix = $kind->codePrefix() . '-' . $at->format('Y') . '-';

        $max = 0;
        foreach ($this->findings->findCodesStartingWith($finding->getEducationalCentre(), $prefix) as $code) {
            $number = substr($code, \strlen($prefix));
            if (ctype_digit($number)) {
                $max = max($max, (int) $number);
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', \STR_PAD_LEFT);
    }
}
