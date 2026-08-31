<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentRevision;
use App\Entity\DocumentReviewNotificationEvent;
use App\Entity\DocumentReviewNotificationKind;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Repository\SpecificProfileAssignmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Emails a folder's review-profile holders when a new revision needs their review — deliberately
 * narrower than DocumentTreeAccessChecker::canReviewFolder(): only teachers who personally hold
 * one of the folder's own FolderReviewProfile rows are notified, never a quality manager/admin
 * acting on the folder without holding one of those profiles themselves (that broader "can review"
 * right is about what they're allowed to do if asked, not who's expected to be notified).
 *
 * Each reviewer's notifications.pending_review_notification_mode decides what happens: 'individual'
 * sends right away (as below), 'daily_digest' queues a DocumentReviewNotificationEvent for
 * SendDocumentReviewDigestHandler to pick up that night instead, 'disabled' does nothing. Only one
 * event row is queued per revision regardless of how many reviewers want the digest — the digest
 * handler re-resolves reviewers itself when it builds each teacher's summary.
 */
final class DocumentReviewNotifier
{
    public function __construct(
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly AppSettingsInterface $settings,
        private readonly NotificationMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {}

    public function notifyReviewers(DocumentRevision $revision): void
    {
        $document = $revision->getDocument();
        $folder   = $document->getFolder();
        $centre   = $folder->getEducationalCentre();

        $reviewers = $this->reviewersFor($folder);
        if ($reviewers === []) {
            return;
        }

        $actionUrl = $this->urlGenerator->generate('app_document_tree', [
            'section'  => $folder->getDocumentSection()->getId()->toRfc4122(),
            'folder'   => $folder->getId()->toRfc4122(),
            'document' => $document->getId()->toRfc4122(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $bodyHtml = $this->twig->render('email/_pending_review_reminder_body.html.twig', [
            'document' => $document,
            'folder'   => $folder,
        ]);

        $needsDigest = false;
        foreach ($reviewers as $reviewer) {
            $mode = $this->settings->getForTeacherInCentre('notifications.pending_review_notification_mode', $reviewer, $centre);
            if ($mode === 'daily_digest') {
                $needsDigest = true;

                continue;
            }
            if ($mode !== 'individual') {
                continue;
            }

            $this->mailer->send(
                $reviewer,
                $centre,
                'pending_review_reminder',
                $this->translator->trans('emails.pending_review_reminder.subject', [], 'emails'),
                $this->translator->trans('emails.pending_review_reminder.heading', [], 'emails'),
                $bodyHtml,
                $actionUrl,
                $this->translator->trans('emails.pending_review_reminder.cta', [], 'emails'),
            );
        }

        if ($needsDigest) {
            $this->em->persist(new DocumentReviewNotificationEvent($revision, DocumentReviewNotificationKind::PendingReview));
            $this->em->flush();
        }
    }

    /**
     * @return list<Teacher> teachers personally holding one of the folder's review profiles,
     *                       deduplicated — public so SendDocumentReviewDigestHandler can re-resolve
     *                       which teacher(s) a queued PendingReview event applies to.
     */
    public function reviewersFor(Folder $folder): array
    {
        $teachers = [];
        $seen     = [];
        foreach ($folder->getReviewProfiles() as $restriction) {
            foreach ($this->assignments->findTeachersHoldingProfileAndListItem($restriction->getSpecificProfile(), $restriction->getListItem()) as $teacher) {
                $key = $teacher->getId()->toRfc4122();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $teachers[] = $teacher;
            }
        }

        return $teachers;
    }
}
