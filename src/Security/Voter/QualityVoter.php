<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\ImprovementAction;
use App\Entity\Indicator;
use App\Entity\Teacher;
use App\Service\DocumentTreeAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * "Mejora continua" permissions. Reporting an incident needs nothing beyond access to the centre
 * (every route there already requires it). On top of that:
 *
 * - MANAGE (centre): classify, assign, verify and close — the centre's admins and quality managers
 *   (and platform admins), who answer for the quality system.
 * - VIEW_ALL (centre): see every finding — those, plus the internal auditors.
 * - FINDING_VIEW: VIEW_ALL, or whoever reported it, analyses it or is responsible of one of its
 *   actions (directly or through a profile they hold).
 * - FINDING_ANALYZE: MANAGE, or its analysis responsible.
 * - ACTION_WORK: MANAGE, or the action's responsible teacher, or anyone holding its profile.
 * - ACTION_VIEW: VIEW_ALL or ACTION_WORK; for a finding's action, also its FINDING_VIEW.
 * - INDICATOR_VIEW: VIEW_ALL, or the indicator's responsible (teacher, or anyone holding its profile).
 * - INDICATOR_RECORD (record its values): MANAGE, or its responsible.
 *
 * @extends Voter<string, EducationalCentre|Finding|ImprovementAction|Indicator>
 */
final class QualityVoter extends Voter
{
    public const string MANAGE          = 'quality.manage';
    public const string VIEW_ALL        = 'quality.view_all';
    public const string FINDING_VIEW    = 'quality.finding_view';
    public const string FINDING_ANALYZE = 'quality.finding_analyze';
    public const string ACTION_WORK     = 'quality.action_work';
    public const string ACTION_VIEW     = 'quality.action_view';
    public const string INDICATOR_VIEW   = 'quality.indicator_view';
    public const string INDICATOR_RECORD = 'quality.indicator_record';

    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::MANAGE, self::VIEW_ALL              => $subject instanceof EducationalCentre,
            self::FINDING_VIEW, self::FINDING_ANALYZE => $subject instanceof Finding,
            self::ACTION_WORK, self::ACTION_VIEW       => $subject instanceof ImprovementAction,
            self::INDICATOR_VIEW, self::INDICATOR_RECORD => $subject instanceof Indicator,
            default                                    => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        return match (true) {
            $subject instanceof EducationalCentre => $attribute === self::MANAGE ? $this->manages($user, $subject) : $this->seesAll($user, $subject),
            $subject instanceof Finding           => $attribute === self::FINDING_ANALYZE ? $this->canAnalyze($user, $subject) : $this->canView($user, $subject),
            $subject instanceof ImprovementAction => $attribute === self::ACTION_VIEW ? $this->canViewAction($user, $subject) : $this->canWork($user, $subject),
            $subject instanceof Indicator         => $this->isIndicatorResponsible($user, $subject)
                || ($attribute === self::INDICATOR_VIEW ? $this->seesAll($user, $subject->getEducationalCentre()) : $this->manages($user, $subject->getEducationalCentre())),
        };
    }

    private function manages(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $teacher->isAdmin() || self::among($teacher, $centre->getAdmins()) || self::among($teacher, $centre->getQualityManagers());
    }

    private function seesAll(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $this->manages($teacher, $centre) || self::among($teacher, $centre->getInternalAuditors());
    }

    private function canAnalyze(Teacher $teacher, Finding $finding): bool
    {
        return $this->manages($teacher, $finding->getEducationalCentre()) || self::same($teacher, $finding->getAnalysisResponsible());
    }

    private function canView(Teacher $teacher, Finding $finding): bool
    {
        if ($this->seesAll($teacher, $finding->getEducationalCentre())
            || self::same($teacher, $finding->getReportedBy())
            || self::same($teacher, $finding->getAnalysisResponsible())) {
            return true;
        }

        foreach ($finding->getActions() as $action) {
            if ($this->isResponsible($teacher, $action)) {
                return true;
            }
        }

        return false;
    }

    private function canWork(Teacher $teacher, ImprovementAction $action): bool
    {
        return $this->manages($teacher, $action->getEducationalCentre()) || $this->isResponsible($teacher, $action);
    }

    private function canViewAction(Teacher $teacher, ImprovementAction $action): bool
    {
        $finding = $action->getFinding();

        return $this->seesAll($teacher, $action->getEducationalCentre())
            || $this->canWork($teacher, $action)
            || ($finding !== null && $this->canView($teacher, $finding));
    }

    private function isIndicatorResponsible(Teacher $teacher, Indicator $indicator): bool
    {
        if (self::same($teacher, $indicator->getResponsibleTeacher())) {
            return true;
        }

        $profile = $indicator->getResponsibleProfile();

        return $profile !== null && $this->access->holdsProfile($teacher, $profile, null);
    }

    private function isResponsible(Teacher $teacher, ImprovementAction $action): bool
    {
        if (self::same($teacher, $action->getResponsibleTeacher())) {
            return true;
        }

        $profile = $action->getResponsibleProfile();

        return $profile !== null && $this->access->holdsProfile($teacher, $profile, null);
    }

    /** By id, not by instance: the logged-in user and the one loaded with a finding can be different objects. */
    private static function same(Teacher $teacher, ?Teacher $other): bool
    {
        return $other !== null && $other->getId()->equals($teacher->getId());
    }

    /** @param iterable<Teacher> $teachers */
    private static function among(Teacher $teacher, iterable $teachers): bool
    {
        foreach ($teachers as $other) {
            if (self::same($teacher, $other)) {
                return true;
            }
        }

        return false;
    }
}
