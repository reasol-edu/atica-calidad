<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Document;
use App\Service\ActivityDeadlineChecker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * An activity folder keeps every academic year's submissions, often under the very same name
 * («Tutor/a»): past years' ones get labelled with their academic year so they can be told apart
 * from this year's in the document tree.
 */
final class ActivityCycleExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActivityDeadlineChecker $deadline,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('past_submission_academic_year', $this->pastSubmissionAcademicYear(...)),
        ];
    }

    /** "2025-2026" if $document is a submission for an earlier occurrence of its folder's activity than the current one; null otherwise. */
    public function pastSubmissionAcademicYear(Document $document): ?string
    {
        $cycleYear = $document->getActivityCycleYear();
        $activity  = $document->getFolder()->getActivity();
        if ($cycleYear === null || $activity === null || $cycleYear === $this->deadline->currentCycleKey($activity)) {
            return null;
        }

        return \sprintf('%d-%d', $cycleYear, $cycleYear + 1);
    }
}
