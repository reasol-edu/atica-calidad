<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;

/**
 * One activity as seen by the start-of-year review (ActivityYearReviewBuilder): what it asks for
 * and from whom, whether those people exist in the year being prepared, and what is off.
 */
final readonly class ActivityYearReviewRow
{
    /** Nobody would be asked: no upload profile (or no element of the list matches the tags and a profile). */
    public const string ISSUE_NOBODY_ASKED = 'nobody_asked';
    /** Every profile asked for the activity has nobody assigned among the year's teachers. */
    public const string ISSUE_NOBODY_IN_YEAR = 'nobody_in_year';
    /** Some of the profiles asked (not all) have nobody assigned among the year's teachers. */
    public const string ISSUE_SOME_UNSTAFFED = 'some_unstaffed';
    /** It has responsible profiles and none of them has anybody in the year. */
    public const string ISSUE_RESPONSIBLE_UNSTAFFED = 'responsible_unstaffed';

    /**
     * @param list<array{name: string, teachers: int}> $asked       profiles/subprofiles asked, with how many of the year's teachers hold each
     * @param list<array{name: string, teachers: int}> $responsible  the same for the profiles that manage it
     * @param list<string>                             $issues       ISSUE_* keys
     */
    public function __construct(
        public Activity $activity,
        public string $categoryName,
        public bool $withSubmissions,
        /** True for a general manual activity: asked of every teacher of the year, no profiles involved. */
        public bool $everyone,
        public int $submissions,
        public array $asked,
        public array $responsible,
        public array $issues,
    ) {}

    public function hasIssues(): bool
    {
        return $this->issues !== [];
    }
}
