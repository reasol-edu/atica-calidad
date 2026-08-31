<?php

declare(strict_types=1);

namespace App\Entity;

/** Which of the three document-review events a queued DocumentReviewNotificationEvent represents. */
enum DocumentReviewNotificationKind: string
{
    case PendingReview = 'pending_review';
    case Approved      = 'approved';
    case Rejected      = 'rejected';
}
