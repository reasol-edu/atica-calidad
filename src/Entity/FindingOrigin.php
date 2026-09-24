<?php

declare(strict_types=1);

namespace App\Entity;

/** Where a finding came from. A teacher's report is InternalReport; the rest are set by the quality manager (or, later, by an audit or an indicator). */
enum FindingOrigin: string
{
    case InternalReport = 'internal_report';
    case InternalAudit = 'internal_audit';
    case ExternalAudit = 'external_audit';
    case Complaint = 'complaint';
    case Indicator = 'indicator';
    case ManagementReview = 'management_review';
}
