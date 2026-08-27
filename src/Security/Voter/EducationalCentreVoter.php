<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, EducationalCentre>
 */
final class EducationalCentreVoter extends Voter
{
    /** Access to the educational centre management section. Subject: EducationalCentre */
    public const SECTION = 'educational_centre.section';

    /**
     * Access to the Responsibilities section (specific profiles, lists, tags).
     * Broader than SECTION: also granted to the centre's quality managers,
     * not just its admins. Subject: EducationalCentre
     */
    public const RESPONSIBILITIES = 'educational_centre.responsibilities';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::SECTION, self::RESPONSIBILITIES], true) && $subject instanceof EducationalCentre;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var EducationalCentre $subject */
        if ($user->isAdmin() || $subject->getAdmins()->contains($user)) {
            return true;
        }

        return $attribute === self::RESPONSIBILITIES && $subject->getQualityManagers()->contains($user);
    }
}
