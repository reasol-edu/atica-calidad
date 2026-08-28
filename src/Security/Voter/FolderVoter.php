<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Folder;
use App\Entity\Teacher;
use App\Service\DocumentTreeAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Folder>
 */
final class FolderVoter extends Voter
{
    /** Manage the folder's documents/revisions and upload with any of its upload profiles. */
    public const MANAGE = 'folder.manage';

    /** Upload a document, but only tagged with an upload profile the uploader themselves holds. */
    public const UPLOAD = 'folder.upload';

    /** Approve or reject a pending revision. */
    public const REVIEW = 'folder.review';

    /** See the folder and its documents at all. */
    public const VIEW = 'folder.view';

    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::MANAGE, self::UPLOAD, self::REVIEW, self::VIEW], true) && $subject instanceof Folder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var Folder $subject */
        return match ($attribute) {
            self::MANAGE => $this->access->canManageFolder($user, $subject),
            self::UPLOAD => $this->access->canUploadToFolder($user, $subject),
            self::REVIEW => $this->access->canReviewFolder($user, $subject),
            self::VIEW   => $this->access->canViewFolder($user, $subject),
            default      => false,
        };
    }
}
