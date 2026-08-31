<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\DocumentReviewNotificationEvent;
use App\Entity\DocumentReviewNotificationKind;
use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Message\SendDocumentReviewDigestMessage;
use App\Repository\DocumentReviewNotificationEventRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use App\Service\AppSettingsInterface;
use App\Service\DocumentReviewNotifier;
use App\Service\DocumentReviewOutcomeNotifier;
use App\Service\NonWorkingDayChecker;
use App\Service\NotificationMailer;
use App\Service\PendingReviewFinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Daily digest (see Schedule.php): for every teacher who has at least one of the three document
 * review events (pending review, accepted, rejected) set to notifications.*_notification_mode ===
 * 'daily_digest' and something to show, sends a single email with up to three sections. Unlike
 * SendPendingActivityReminderHandler, "pending review" isn't purely recomputed live: a queued
 * DocumentReviewNotificationEvent (see that entity's docblock) marks a revision as "new since the
 * last digest", and PendingReviewFinder::forTeacher()'s live result minus whatever's "new" is the
 * "still pending from before" rest — approved/rejected events have no such "rest" bucket, since
 * they're one-off and vanish from the queue as soon as they're shown once. Skips a centre outright
 * on a weekend or one of its declared non-working days (NonWorkingDayChecker), same as the
 * activity reminder.
 */
#[AsMessageHandler]
final class SendDocumentReviewDigestHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TeacherRepository $teachers,
        private readonly AppSettingsInterface $settings,
        private readonly DocumentReviewNotificationEventRepository $events,
        private readonly PendingReviewFinder $pendingReviewFinder,
        private readonly DocumentReviewNotifier $reviewNotifier,
        private readonly DocumentReviewOutcomeNotifier $outcomeNotifier,
        private readonly NotificationMailer $mailer,
        private readonly NonWorkingDayChecker $nonWorkingDays,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {}

    public function __invoke(SendDocumentReviewDigestMessage $message): void
    {
        $today = $this->clock->now();

        foreach ($this->centres->findAllWithActiveYear() as $centre) {
            $year = $centre->getActiveAcademicYear();
            if ($year === null || $this->nonWorkingDays->isNonWorkingDay($year, $today)) {
                continue;
            }

            $events = $this->events->findByCentre($centre);

            foreach ($this->teachers->findByAcademicYearOrderedByName($year) as $teacher) {
                $this->digestForTeacher($teacher, $centre, $events);
            }

            foreach ($events as $event) {
                $this->em->remove($event);
            }
            $this->em->flush();
        }
    }

    /** @param list<DocumentReviewNotificationEvent> $events */
    private function digestForTeacher(Teacher $teacher, EducationalCentre $centre, array $events): void
    {
        $pendingNew  = [];
        $pendingRest = [];
        if ($this->settings->getForTeacherInCentre('notifications.pending_review_notification_mode', $teacher, $centre) === 'daily_digest') {
            $newIds = [];
            foreach ($events as $event) {
                if ($event->getKind() !== DocumentReviewNotificationKind::PendingReview) {
                    continue;
                }
                $revision = $event->getDocumentRevision();
                if (!in_array($teacher, $this->reviewNotifier->reviewersFor($revision->getDocument()->getFolder()), true)) {
                    continue;
                }
                $pendingNew[] = $revision;
                $newIds[$revision->getId()->toRfc4122()] = true;
            }

            foreach ($this->pendingReviewFinder->forTeacher($teacher, $centre) as $revision) {
                if (isset($newIds[$revision->getId()->toRfc4122()])) {
                    continue;
                }
                $pendingRest[] = $revision;
            }
        }

        $accepted = $this->settings->getForTeacherInCentre('notifications.document_accepted_notification_mode', $teacher, $centre) === 'daily_digest'
            ? $this->eventsFor($events, DocumentReviewNotificationKind::Approved, $teacher)
            : [];
        $rejected = $this->settings->getForTeacherInCentre('notifications.document_rejected_notification_mode', $teacher, $centre) === 'daily_digest'
            ? $this->eventsFor($events, DocumentReviewNotificationKind::Rejected, $teacher)
            : [];

        if ($pendingNew === [] && $pendingRest === [] && $accepted === [] && $rejected === []) {
            return;
        }

        $bodyHtml = $this->twig->render('email/_document_review_digest_body.html.twig', [
            'pendingNew'  => $pendingNew,
            'pendingRest' => $pendingRest,
            'accepted'    => $accepted,
            'rejected'    => $rejected,
        ]);

        $this->mailer->send(
            $teacher,
            $centre,
            'document_review_digest',
            $this->translator->trans('emails.document_review_digest.subject', [], 'emails'),
            $this->translator->trans('emails.document_review_digest.heading', [], 'emails'),
            $bodyHtml,
            $this->urlGenerator->generate('app_document_tree', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->translator->trans('emails.document_review_digest.cta', [], 'emails'),
        );
    }

    /**
     * @param  list<DocumentReviewNotificationEvent> $events
     * @return list<DocumentRevision>
     */
    private function eventsFor(array $events, DocumentReviewNotificationKind $kind, Teacher $teacher): array
    {
        $revisions = [];
        foreach ($events as $event) {
            if ($event->getKind() !== $kind) {
                continue;
            }
            $revision = $event->getDocumentRevision();
            if (!in_array($teacher, $this->outcomeNotifier->recipientsFor($revision->getDocument()->getFolder(), $revision), true)) {
                continue;
            }
            $revisions[] = $revision;
        }

        return $revisions;
    }
}
