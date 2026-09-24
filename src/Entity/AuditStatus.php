<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where an internal audit is: planned in the programme, being prepared (date, checklist), being
 * carried out, its report issued (its findings being dealt with) or closed. The marking of the
 * "audit" state machine (config/packages/workflow.yaml).
 */
enum AuditStatus: string
{
    case Planned = 'planned';
    case Preparation = 'preparation';
    case InProgress = 'in_progress';
    case ReportIssued = 'report_issued';
    case Closed = 'closed';

    /** Still to be carried out: planned, being prepared or in progress. */
    public function isPending(): bool
    {
        return \in_array($this, [self::Planned, self::Preparation, self::InProgress], true);
    }
}
