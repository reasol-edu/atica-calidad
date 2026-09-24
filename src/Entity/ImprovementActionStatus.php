<?php

declare(strict_types=1);

namespace App\Entity;

/** A linear life cycle, so a plain status rather than a workflow (see the "finding" state machine for the contrast). */
enum ImprovementActionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
}
