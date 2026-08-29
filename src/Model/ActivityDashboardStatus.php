<?php

declare(strict_types=1);

namespace App\Model;

/** The three states the dashboard's activity summary distinguishes visually. */
enum ActivityDashboardStatus: string
{
    case Completed = 'completed';
    case Pending = 'pending';
    case Overdue = 'overdue';
}
