<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\DocumentTreeAccessChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Whether a search result for a document may be shown to a teacher. The one place both the name search and the content search
 * ask, so what a person finds never depends on which of the two found it: the folder's own visibility rules, and a submission
 * of a hidden activity only for whoever can edit activities.
 */
final class DocumentSearchVisibility
{
    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {}

    public function canSee(Teacher $teacher, Document $document, EducationalCentre $centre): bool
    {
        $hiddenSubmission = $document->getFolder()->getActivity()?->isHidden() === true
            && !$this->authorization->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);

        return !$hiddenSubmission && $this->access->canViewDocument($teacher, $document);
    }
}
