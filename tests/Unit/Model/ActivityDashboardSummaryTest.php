<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ActivityDashboardSummary;
use PHPUnit\Framework\TestCase;

final class ActivityDashboardSummaryTest extends TestCase
{
    public function testCompletionPercentageIsZeroWhenThereIsNothingApplicable(): void
    {
        $summary = new ActivityDashboardSummary(0, 0, 0, 0, []);

        self::assertSame(0, $summary->completionPercentage());
    }

    public function testCompletionPercentageRoundsToTheNearestInteger(): void
    {
        $summary = new ActivityDashboardSummary(3, 1, 2, 0, []);

        self::assertSame(33, $summary->completionPercentage());
    }

    public function testCompletionPercentageIsOneHundredWhenEverythingIsCompleted(): void
    {
        $summary = new ActivityDashboardSummary(4, 4, 0, 0, []);

        self::assertSame(100, $summary->completionPercentage());
    }
}
