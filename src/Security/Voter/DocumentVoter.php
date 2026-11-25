<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\Teacher;
use App\Service\DocumentTreeAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What can be done with one document, as opposed to its whole folder (FolderVoter): the routes that serve a single document — its
 * files, its delivered form, its annexes — ask this one, so the teachers associated with the document's element can open what they
 * delivered even when the folder itself is not visible to them. The folder's ZIP keeps asking FolderVoter::VIEW.
 *
 * @extends Voter<string, Document>
 */
final class DocumentVoter extends Voter
{
    /** Open the document: download its revisions, see its delivered form and annexes. */
    public const VIEW = 'document.view';

    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof Document;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        /** @var Document $subject */
        return $user instanceof Teacher && $this->access->canOpenDocument($user, $subject);
    }
}
