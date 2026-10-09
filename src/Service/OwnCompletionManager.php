<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\OwnCompletionOutcome;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A teacher marking one of their OWN obligations of a manual activity as done: from the activity's
 * card and from the dashboard's list. The owner arrives as client-supplied ids (profile / list item /
 * element), so it is re-resolved here and denied unless it is one the teacher actually holds — a
 * button in the page is not enough on its own. Respects the activity's window, logs the completion
 * (with the "late" flag), and leaves the rest (managers acting for someone else) to the browser.
 */
class OwnCompletionManager
{
    public function __construct(
        private readonly SpecificProfileRepository $profiles,
        private readonly ListItemRepository $listItems,
        private readonly ActivityCompletionChecker $completion,
        private readonly ActivityWindowChecker $windows,
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * The owner a (un)mark call targets, or AccessDeniedException unless it is one $teacher is
     * really offered: their own individual completion when the activity has one, or one of the
     * profile / list item / element rows they hold.
     *
     * @return array{0: ?SpecificProfile, 1: ?ListItem, 2: ?ListItem}
     */
    public function resolveOwner(Teacher $teacher, Activity $activity, EducationalCentre $centre, string $profileId, string $listItemId, string $leafId): array
    {
        $profile  = $profileId === '' ? null : $this->profiles->findByIdAndCentre($profileId, $centre);
        $listItem = $listItemId === '' ? null : $this->listItems->findByIdAndCentre($listItemId, $centre);
        $leaf     = $leafId === '' ? null : $this->listItems->findByIdAndCentre($leafId, $centre);

        if (($profileId !== '' && $profile === null) || ($listItemId !== '' && $listItem === null) || ($leafId !== '' && $leaf === null)) {
            throw new AccessDeniedException();
        }

        if ($profile === null) {
            if ($listItem !== null || $leaf !== null || !$this->completion->hasIndividualCompletionOwner($activity)
                || !$this->completion->isApplicableToTeacher($teacher, $activity)) {
                throw new AccessDeniedException();
            }

            return [null, null, null];
        }

        foreach ($this->completion->getMyCompletionOwners($teacher, $activity) as $owner) {
            if ($owner['profile'] === $profile && $owner['listItem'] === $listItem && $owner['leaf'] === $leaf) {
                return [$profile, $listItem, $leaf];
            }
        }

        throw new AccessDeniedException();
    }

    public function mark(Teacher $teacher, Activity $activity, EducationalCentre $centre, string $profileId = '', string $listItemId = '', string $leafId = ''): OwnCompletionOutcome
    {
        // Auto-complete activities have nothing to mark: their status is always computed.
        if ($activity->isAutoComplete()) {
            return OwnCompletionOutcome::Unchanged;
        }

        [$profile, $listItem, $leaf] = $this->resolveOwner($teacher, $activity, $centre, $profileId, $listItemId, $leafId);
        $targetTeacher               = $profile === null ? $teacher : null;

        $window = $this->windows->for($activity, $teacher, $leaf);
        if ($window->blocked) {
            return OwnCompletionOutcome::OutOfWindow;
        }

        if (!$this->completion->markCompleted($activity, $targetTeacher, $profile, $listItem, $teacher, $leaf)) {
            return OwnCompletionOutcome::Unchanged;
        }

        $this->em->flush();

        // Explicit log entry (with the "late" flag): its presence suppresses the generic per-request one.
        $logData = ['activity' => $activity->getTitle()];
        if ($window->late) {
            $logData['late'] = true;
        }
        $this->activityLogger->record('activity.mark_complete', $logData, $centre);

        return OwnCompletionOutcome::Marked;
    }
}
