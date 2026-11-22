<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\FormApprovalStep;
use App\Entity\FormApprovalStepState;
use App\Entity\FormSubmissionRevision;
use App\Entity\Teacher;
use App\Repository\FormApprovalStepRepository;
use App\Repository\SpecificProfileRepository;
use App\Service\DocumentTreeAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The approval of a delivered document in several ordered steps, as its model defines them: each step is decided by whoever holds
 * the step's profile (and, to unblock a chain, whoever manages the folder). Only the current step can be decided; approving the last
 * one approves the revision, and a rejection at any step rejects it — the chain starts over from the first step with the next
 * delivery. DocumentRevisionReviewer is the one entry point (the tree, the review queue and the bulk review all end up there), so no
 * screen can approve around the chain.
 */
final class FormApprovalChain
{
    public const string NEXT          = 'next';
    public const string FINAL_APPROVE = 'final_approve';
    public const string FINAL_REJECT  = 'final_reject';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormApprovalStepRepository $steps,
        private readonly SpecificProfileRepository $profiles,
        private readonly DocumentTreeAccessChecker $access,
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
        $profile = $step->getProfile();

        return $profile !== null && $this->access->holdsProfile($teacher, $profile, null);
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
