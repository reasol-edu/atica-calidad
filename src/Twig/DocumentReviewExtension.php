<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Document;
use App\Service\DocumentReviewSchedule;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** How close a document's next review is, for the badge next to it in the document tree (see DocumentReviewSchedule). */
final class DocumentReviewExtension extends AbstractExtension
{
    public function __construct(
        private readonly DocumentReviewSchedule $schedule,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('next_review_state', fn (Document $document): ?string => $this->schedule->stateOf($document->getNextReviewAt())),
        ];
    }
}
