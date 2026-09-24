<?php

declare(strict_types=1);

namespace App\Entity;

/** What a FindingTimelineEntry records. The first ones mirror the "finding" workflow's transitions. */
enum FindingEventKind: string
{
    case Reported = 'reported';
    case Classified = 'classified';
    case Discarded = 'discarded';
    case AnalysisSubmitted = 'analysis_submitted';
    case VerificationRequested = 'verification_requested';
    case VerifiedEffective = 'verified_effective';
    case VerifiedIneffective = 'verified_ineffective';
    case Closed = 'closed';
    case ActionAdded = 'action_added';
    case ActionStarted = 'action_started';
    case ActionDone = 'action_done';
    case AttachmentAdded = 'attachment_added';
    case Comment = 'comment';
}
