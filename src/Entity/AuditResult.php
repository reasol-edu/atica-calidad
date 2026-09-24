<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What an audit found for one point of its checklist. A nonconformity, an observation or an
 * improvement opportunity becomes a finding of that kind when the report is issued.
 */
enum AuditResult: string
{
    case Conforming = 'conforming';
    case Observation = 'observation';
    case Nonconformity = 'nonconformity';
    case Improvement = 'improvement';
    case NotApplicable = 'not_applicable';

    /** The kind of finding it becomes, if any. */
    public function findingKind(): ?FindingKind
    {
        return match ($this) {
            self::Nonconformity => FindingKind::Nonconformity,
            self::Observation   => FindingKind::Observation,
            self::Improvement   => FindingKind::ImprovementOpportunity,
            default             => null,
        };
    }
}
