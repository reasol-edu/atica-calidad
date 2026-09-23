<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ActivityDashboardSummary;
use PHPUnit\Framework\TestCase;

final class ActivityDashboardSummaryTest extends TestCase
{
    public function testCompletionPercentageIsZeroWhenThereIsNothingApplicable(): void
    {
        $summary = new ActivityDashboardSummary(total: 0, todo: 0, overdue: 0, inReview: 0, completed: 0, nextSteps: [], nextUpcoming: null);

        self::assertSame(0, $summary->completionPercentage());
    }

    public function testCompletionPercentageRoundsToTheNearestInteger(): void
    {
        $summary = new ActivityDashboardSummary(total: 3, todo: 2, overdue: 0, inReview: 0, completed: 1, nextSteps: [], nextUpcoming: null);

        self::assertSame(33, $summary->completionPercentage());
    }

    public function testCompletionPercentageIsOneHundredWhenEverythingIsCompleted(): void
    {
        $summary = new ActivityDashboardSummary(total: 4, todo: 0, overdue: 0, inReview: 0, completed: 4, nextSteps: [], nextUpcoming: null);

        self::assertSame(100, $summary->completionPercentage());
    }
}
