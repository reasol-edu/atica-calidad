<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActivitySubmissionScope;
use App\Entity\DocumentReviewNotificationEvent;
use App\Entity\DocumentReviewNotificationKind;
use App\Entity\DocumentRevision;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Repository\SpecificProfileAssignmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Emails whoever should know a document revision was approved or rejected — mirrors
 * DocumentReviewNotifier's individual/daily_digest/disabled gating and event-queuing exactly, just
 * for the opposite end of the review (the outcome, not the request), and with a different
 * recipient rule: recipientsFor() answers "who should hear about this document", which is either
 * the folder's upload-profile holders, or — when the activity backing the folder scopes
 * submissions individually — just the one teacher who uploaded this particular revision, since in
 * that scope the rest of the profile never shares this submission.
 */
final class DocumentReviewOutcomeNotifier
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

    public function notifyOutcome(DocumentRevision $revision, DocumentReviewNotificationKind $kind): void
    {
        $document = $revision->getDocument();
        $folder   = $document->getFolder();
        $centre   = $folder->getEducationalCentre();

        $recipients = $this->recipientsFor($folder, $revision);
        if ($recipients === []) {
            return;
        }

        $settingKey = $kind === DocumentReviewNotificationKind::Approved
            ? 'notifications.document_accepted_notification_mode'
            : 'notifications.document_rejected_notification_mode';
        $eventKey   = $kind === DocumentReviewNotificationKind::Approved ? 'document_accepted' : 'document_rejected';
        $template   = $kind === DocumentReviewNotificationKind::Approved
            ? 'email/_document_accepted_body.html.twig'
            : 'email/_document_rejected_body.html.twig';

        $actionUrl = $this->urlGenerator->generate('app_document_tree', [
            'section'  => $folder->getDocumentSection()->getId()->toRfc4122(),
            'folder'   => $folder->getId()->toRfc4122(),
            'document' => $document->getId()->toRfc4122(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $bodyHtml = $this->twig->render($template, [
            'document' => $document,
            'folder'   => $folder,
            'revision' => $revision,
        ]);

        $needsDigest = false;
        foreach ($recipients as $recipient) {
            $mode = $this->settings->getForTeacherInCentre($settingKey, $recipient, $centre);
            if ($mode === 'daily_digest') {
                $needsDigest = true;

                continue;
            }
            if ($mode !== 'individual') {
                continue;
            }

            $this->mailer->send(
                $recipient,
                $centre,
                $eventKey,
                $this->translator->trans("emails.{$eventKey}.subject", [], 'emails'),
                $this->translator->trans("emails.{$eventKey}.heading", [], 'emails'),
                $bodyHtml,
                $actionUrl,
                $this->translator->trans("emails.{$eventKey}.cta", [], 'emails'),
            );
        }

        if ($needsDigest) {
            $this->em->persist(new DocumentReviewNotificationEvent($revision, $kind));
            $this->em->flush();
        }
    }

    /**
     * @return list<Teacher> everyone who should hear about a document's review outcome — public so
     *                       SendDocumentReviewDigestHandler can re-resolve which teacher(s) a queued
     *                       Approved/Rejected event applies to.
     */
    public function recipientsFor(Folder $folder, DocumentRevision $revision): array
    {
        $activity = $folder->getActivity();
        if ($activity !== null && $activity->getSubmissionScope() === ActivitySubmissionScope::Individual) {
            return [$revision->getUploadedBy()];
        }

        $teachers = [];
        $seen     = [];
        foreach ($folder->getUploadProfiles() as $restriction) {
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
