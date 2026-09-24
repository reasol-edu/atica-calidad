<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What the quality manager classified a reported incident as. Only a nonconformity goes through
 * cause analysis and effectiveness verification (ISO 9001 10.2); the other two may carry actions
 * and close once they're done.
 */
enum FindingKind: string
{
    case Nonconformity = 'nonconformity';
    case Observation = 'observation';
    case ImprovementOpportunity = 'improvement_opportunity';

    /** Prefix of its code: NC-2026-014, OB-…, OM-…. */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Nonconformity          => 'NC',
            self::Observation            => 'OB',
            self::ImprovementOpportunity => 'OM',
        };
    }
}
