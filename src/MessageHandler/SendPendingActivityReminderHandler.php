<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Message\SendPendingActivityReminderMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use App\Service\AppSettingsInterface;
use App\Service\NonWorkingDayChecker;
use App\Service\NotificationMailer;
use App\Service\PendingActivityReminderFinder;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Daily digest (see Schedule.php): for every teacher with the reminder enabled
 * (notifications.pending_activity_reminder_enabled) who has at least one activity due soon or
 * overdue, sends a single email summarising both — see PendingActivityReminderFinder for exactly
 * what counts as "due soon" vs "overdue" vs excluded entirely. Skips a centre outright on a
 * weekend or one of its declared non-working days (NonWorkingDayChecker) — nobody's expected to
 * be completing activities then, so a reminder would just be noise.
 */
#[AsMessageHandler]
final class SendPendingActivityReminderHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TeacherRepository $teachers,
        private readonly AppSettingsInterface $settings,
        private readonly PendingActivityReminderFinder $finder,
        private readonly NotificationMailer $mailer,
        private readonly NonWorkingDayChecker $nonWorkingDays,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {}

    public function __invoke(SendPendingActivityReminderMessage $message): void
    {
        $today = $this->clock->now();

        foreach ($this->centres->findAllWithActiveYear() as $centre) {
            $year = $centre->getActiveAcademicYear();
            if ($year === null || $this->nonWorkingDays->isNonWorkingDay($year, $today)) {
                continue;
            }

            foreach ($this->teachers->findByAcademicYearOrderedByName($year) as $teacher) {
                $this->remindTeacher($teacher, $centre);
            }
        }
    }

    private function remindTeacher(Teacher $teacher, EducationalCentre $centre): void
    {
        if (!$this->settings->getForTeacherInCentre('notifications.pending_activity_reminder_enabled', $teacher, $centre)) {
            return;
        }

        $warningDays = $this->settings->getForTeacherInCentre('notifications.pending_activity_reminder_warning_days', $teacher, $centre);
        if (!is_int($warningDays) || $warningDays < 0) {
            return;
        }

        $result = $this->finder->forTeacher($teacher, $centre, $warningDays);
        if ($result['dueSoon'] === [] && $result['overdue'] === []) {
            return;
        }

        $bodyHtml = $this->twig->render('email/_pending_activity_reminder_body.html.twig', [
            'dueSoon' => $result['dueSoon'],
            'overdue' => $result['overdue'],
        ]);

        $this->mailer->send(
            $teacher,
            $centre,
            'pending_activity_reminder',
            $this->translator->trans('emails.pending_activity_reminder.subject', [], 'emails'),
            $this->translator->trans('emails.pending_activity_reminder.heading', [], 'emails'),
            $bodyHtml,
            $this->urlGenerator->generate('app_activities', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->translator->trans('emails.pending_activity_reminder.cta', [], 'emails'),
        );
    }
}
