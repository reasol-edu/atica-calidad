<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityReminder;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Sends the e-mail «Recordar a pendientes» to some of the teachers who still have something to do for an activity, records each one
 * (ActivityReminder: to whom, when, by whom, the words and whether it went out) and logs the sending. Who may send and how often is
 * the caller's: «Recordar a pendientes» of the activity (ActivityController::remindPending()) and «Recordar» on a row of a table of
 * deliveries (FormFillController::remindDelivery()).
 */
final class ActivityReminderSender
{
    public function __construct(
        private readonly NotificationMailer $mailer,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * @param list<array{teacher: Teacher, items: non-empty-list<ActivityDashboardItem>}> $pending each recipient (with an address) and
     *                                                                                    the obligations the e-mail lists
     *
     * @return int how many e-mails went out
     */
    public function send(Activity $activity, array $pending, Teacher $sender, string $message, EducationalCentre $centre, string $logEvent = 'activity.remind_pending'): int
    {
        $delivered = 0;
        foreach ($pending as ['teacher' => $recipient, 'items' => $items]) {
            // The button lands on this teacher's own row to fill in, not just on the activity.
            $url  = $this->urls->generate('app_activities', $items[0]->linkParams(), UrlGeneratorInterface::ABSOLUTE_URL);
            $sent = $this->mailer->send(
                $recipient,
                $centre,
                'activity_manual_reminder',
                $this->translator->trans('emails.activity_manual_reminder.subject', ['%title%' => $activity->getTitle()], 'emails'),
                $this->translator->trans('emails.activity_manual_reminder.heading', ['%title%' => $activity->getTitle()], 'emails'),
                $this->twig->render('email/_activity_manual_reminder_body.html.twig', ['sender' => $sender, 'activity' => $activity, 'items' => $items, 'message' => $message]),
                $url,
                $this->translator->trans('emails.activity_manual_reminder.cta', [], 'emails'),
            );
            $delivered += $sent ? 1 : 0;
            $this->em->persist(new ActivityReminder($activity, $recipient, $sender, $this->clock->now(), $message === '' ? null : $message, $sent));
        }
        $this->em->flush();

        $this->activityLogger->record($logEvent, ['activity' => $activity->getTitle(), 'recipients' => \count($pending), 'delivered' => $delivered, 'message' => $message !== ''], $centre);

        return $delivered;
    }
}
