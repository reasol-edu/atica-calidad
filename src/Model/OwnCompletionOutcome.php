<?php

declare(strict_types=1);

namespace App\Model;

/** What happened when a teacher tried to mark one of their own manual obligations as done (OwnCompletionManager). */
enum OwnCompletionOutcome
{
    /** Recorded. */
    case Marked;
    /** The activity's window (or its enforced dates) doesn't allow it now. */
    case OutOfWindow;
    /** Nothing to do: already marked, or the activity completes by itself. */
    case Unchanged;
}
