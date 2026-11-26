<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\FormApprovalStep;
use App\Entity\FormApprovalStepState;
use App\Entity\FormSubmissionRevision;
use App\Entity\ListItem;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Repository\FormApprovalStepRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileRepository;
use App\Service\DocumentTreeAccessChecker;
use App\Service\ListItemOwners;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The approval of a delivered document in several ordered steps, as its model defines them: each step is decided by whoever holds
 * the step's profile (and, to unblock a chain, whoever manages the folder). Only the current step can be decided; approving the last
 * one approves the revision, and a rejection at any step rejects it — the chain starts over from the first step with the next
 * delivery. DocumentRevisionReviewer is the one entry point (the tree, the review queue and the bulk review all end up there), so no
 * screen can approve around the chain.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class FormApprovalChain implements ResetInterface
{
    public const string NEXT          = 'next';
    public const string FINAL_APPROVE = 'final_approve';
    public const string FINAL_REJECT  = 'final_reject';

    /** @var array<string, list<ListItem>|null> elementSubprofiles() per step id for this request */
    private array $subprofilesMemo = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormApprovalStepRepository $steps,
        private readonly SpecificProfileRepository $profiles,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ListItemRepository $listItems,
    ) {}

    /**
     * Whether the model defines a chain: a document delivered with one cannot skip the review of the folder.
     *
     * @param array<string, mixed> $definition
     */
    public static function defines(array $definition): bool
    {
        return FormDefinition::listOf($definition['approval'] ?? []) !== [];
    }

    /**
     * Creates the steps of a delivered revision: the first pending, the rest waiting. Persists, never flushes.
     *
     * @param EducationalCentre $centre where the profiles are looked up
     */
    public function start(FormSubmissionRevision $filled, EducationalCentre $centre): void
    {
        $position = 0;
        foreach (FormDefinition::listOf($filled->getModelRevision()->getDefinition()['approval'] ?? []) as $step) {
            $profile = $this->profiles->findByIdAndCentre(FormDefinition::str($step['profile'] ?? ''), $centre);
            $this->em->persist(new FormApprovalStep(
                $filled,
                ++$position,
                FormDefinition::str($step['name'] ?? ''),
                $profile,
                $position === 1 ? FormApprovalStepState::Pending : FormApprovalStepState::Waiting,
                // Only a profile of a list can be limited to the element (its subprofiles are what elements are associated with).
                ($step['scope'] ?? '') === 'element' && $profile?->getListItem() !== null,
            ));
        }
    }

    /** @return list<FormApprovalStep> */
    public function stepsOf(DocumentRevision $revision): array
    {
        return $this->steps->findByRevision($revision);
    }

    public function currentStep(DocumentRevision $revision): ?FormApprovalStep
    {
        foreach ($this->stepsOf($revision) as $step) {
            if ($step->getState() === FormApprovalStepState::Pending) {
                return $step;
            }
        }

        return null;
    }

    /** Whether the revision is in the middle of a chain (a step waits for a decision). */
    public function hasOpenChain(DocumentRevision $revision): bool
    {
        return $this->currentStep($revision) !== null;
    }

    public function canDecide(Teacher $teacher, FormApprovalStep $step): bool
    {
        if ($step->getState() !== FormApprovalStepState::Pending) {
            return false;
        }
        $folder = $step->getRevision()->getRevision()->getDocument()->getFolder();
        if ($this->access->canManageFolder($teacher, $folder)) {
            return true;
        }

        return $this->holdsStepProfile($teacher, $step);
    }

    /**
     * Whether $teacher is one of the people of a step by their profile — never by managing the folder: anyone with the profile, or,
     * for a step limited to the element, with a subprofile associated with the document's element (see elementSubprofiles()). Who
     * may comment on a delivery, and whose own delivery approves the step at once (approveOwnSteps()).
     */
    public function holdsStepProfile(Teacher $teacher, FormApprovalStep $step): bool
    {
        $profile = $step->getProfile();
        if ($profile === null) {
            return false;
        }
        $subprofiles = $this->elementSubprofiles($step);
        if ($subprofiles === null) {
            return $this->access->holdsProfile($teacher, $profile, null);
        }
        foreach ($subprofiles as $subprofile) {
            if ($this->access->holdsProfile($teacher, $profile, $subprofile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * For a step limited to the element: the subprofiles of the step's profile that the delivery belongs to — those its element is
     * associated with (own and inherited elements of the step's profile's list, ListItemOwners::linkedUnder()) and, when the slot is delivered by that same
     * profile, the subprofile that delivers it (a slot with no element still has one). They are read as they stand now: if a subject is
     * moved to another department, its pending step moves with it (whether the step is limited is what stays as it was at the start).
     * Null when the step is not limited or none is found: then the step works as «anyone with the profile» (isLimitedWithoutElement()),
     * so a chain is never left with nobody to decide it. Worked out once per step and request (the review queue asks for every pending
     * step of the centre).
     *
     * @return list<ListItem>|null
     */
    public function elementSubprofiles(FormApprovalStep $step): ?array
    {
        $profile = $step->getProfile();
        if (!$step->isElementScoped() || $profile === null) {
            return null;
        }
        $memoKey = $step->getId()->toRfc4122();
        if (\array_key_exists($memoKey, $this->subprofilesMemo)) {
            return $this->subprofilesMemo[$memoKey];
        }

        $slotKey     = $step->getRevision()->getSubmission()->getSlotKey();
        $centre      = $step->getRevision()->getRevision()->getDocument()->getFolder()->getEducationalCentre();
        $subprofiles = [];
        $add         = static function (ListItem $subprofile) use (&$subprofiles): void {
            $subprofiles[$subprofile->getId()->toRfc4122()] = $subprofile;
        };

        $leafId = ActivitySubmissionSlot::leafOf($slotKey);
        $leaf   = $leafId === null ? null : $this->listItems->findByIdAndCentre($leafId, $centre);
        $profileList = $profile->getListItem();
        if ($leaf !== null && $profileList !== null) {
            foreach (ListItemOwners::linkedUnder($leaf, $profileList) as $subprofile) {
                $add($subprofile);
            }
        }
        $deliveredBy = ActivitySubmissionSlot::deliveredBy($slotKey);
        if ($deliveredBy !== null && $deliveredBy['profile'] === $profile->getId()->toRfc4122() && $deliveredBy['subprofile'] !== null) {
            $own = $this->listItems->findByIdAndCentre($deliveredBy['subprofile'], $centre);
            if ($own !== null) {
                $add($own);
            }
        }

        return $this->subprofilesMemo[$memoKey] = $subprofiles === [] ? null : array_values($subprofiles);
    }

    /** Any flush may change an element's associations or a step's state: the subprofiles are worked out again. */
    public function postFlush(): void
    {
        $this->subprofilesMemo = [];
    }

    public function reset(): void
    {
        $this->subprofilesMemo = [];
    }

    /** A step limited to the element whose element has no subprofile of its profile: anyone with the profile decides it (said in Ver). */
    public function isLimitedWithoutElement(FormApprovalStep $step): bool
    {
        return $step->isElementScoped() && $this->elementSubprofiles($step) === null;
    }

    /**
     * Decides the current step. Returns what the document needs next: `next` (another step now waits), `final_approve` (the chain is
     * complete: the revision can be approved) or `final_reject` (the revision is rejected, the steps after this one are cancelled).
     *
     * @throws AccessDeniedException when the teacher cannot decide the current step
     */
    public function decide(DocumentRevision $revision, Teacher $by, bool $approve, ?string $note): string
    {
        $all     = $this->stepsOf($revision);
        $current = null;
        foreach ($all as $step) {
            if ($step->getState() === FormApprovalStepState::Pending) {
                $current = $step;
                break;
            }
        }
        if ($current === null) {
            throw new \LogicException('The revision has no step waiting for a decision.');
        }
        if (!$this->canDecide($by, $current)) {
            throw new AccessDeniedException('Only whoever holds the profile of the current step can decide it.');
        }

        if (!$approve) {
            $current->decide(FormApprovalStepState::Rejected, $by, $note);
            foreach ($all as $step) {
                if ($step->getState() === FormApprovalStepState::Waiting) {
                    $step->cancel();
                }
            }
            $this->em->flush();

            return self::FINAL_REJECT;
        }

        $current->decide(FormApprovalStepState::Approved, $by, $note);
        foreach ($all as $step) {
            if ($step->getState() === FormApprovalStepState::Waiting) {
                $step->activate();
                $this->em->flush();

                return self::NEXT;
            }
        }
        $this->em->flush();

        return self::FINAL_APPROVE;
    }

    /**
     * The steps waiting for this teacher in the centre (the ones whose profile they hold), oldest delivery first.
     *
     * @return list<FormApprovalStep>
     */
    public function pendingFor(Teacher $teacher, EducationalCentre $centre): array
    {
        return array_values(array_filter(
            $this->steps->findPendingByCentre($centre),
            fn (FormApprovalStep $step): bool => $this->canDecide($teacher, $step),
        ));
    }
}
