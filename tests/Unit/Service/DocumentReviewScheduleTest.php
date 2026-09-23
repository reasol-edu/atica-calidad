<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DocumentReviewSchedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DocumentReviewScheduleTest extends TestCase
{
    private function stateOf(?string $date): ?string
    {
        return (new DocumentReviewSchedule(new MockClock('2025-10-10 15:00:00')))->stateOf($date === null ? null : new \DateTimeImmutable($date));
    }

    public function testNoDateHasNoState(): void
    {
        self::assertNull($this->stateOf(null));
    }

    public function testADatePassedIsOverdueButTodayIsNotYet(): void
    {
        self::assertSame(DocumentReviewSchedule::OVERDUE, $this->stateOf('2025-10-09'));
        self::assertSame(DocumentReviewSchedule::SOON, $this->stateOf('2025-10-10'));
    }

    public function testWithinThirtyDaysIsSoonAndBeyondIsOk(): void
    {
        self::assertSame(DocumentReviewSchedule::SOON, $this->stateOf('2025-11-09'));
        self::assertSame(DocumentReviewSchedule::OK, $this->stateOf('2025-11-10'));
    }
}
