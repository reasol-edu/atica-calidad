<?php

declare(strict_types=1);

namespace App\Entity;

/** Where a step of the approval chain of a delivered document is: waiting its turn, being decided now, or decided. */
enum FormApprovalStepState: string
{
    case Waiting   = 'waiting';
    case Pending   = 'pending';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    /** Never decided: an earlier step rejected the document. */
    case Cancelled = 'cancelled';
}
