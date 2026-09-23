<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Message\SendDocumentNextReviewReminderMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use App\Service\AppSettingsInterface;
use App\Service\DocumentNextReviewReminderFinder;
use App\Service\DocumentReviewSchedule;
use App\Service\NonWorkingDayChecker;
use App\Service\NotificationMailer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Weekly reminder (see Schedule.php — it runs daily, and sends only on each centre's first working
 * day of the week) of the documents whose next review is overdue or coming up, to whoever is in
 * charge of them (see DocumentNextReviewReminderFinder). Weekly on purpose: a review can stay
 * pending for weeks, and a daily email about the same documents would soon be ignored.
 *
 * "First working day of the week" needs no stored state: today must be a working day and every
 * earlier day of this week (from Monday) a non-working one — so a Monday holiday moves the email
 * to Tuesday, and a missed run on Monday isn't made up on Tuesday.
 */
#[AsMessageHandler]
final class SendDocumentNextReviewReminderHandler
{
    public const string ENABLED_SETTING      = 'notifications.document_next_review_reminder_enabled';
    public const string WARNING_DAYS_SETTING = 'notifications.document_next_review_reminder_warning_days';
    public const string EVENT_KEY            = 'document_next_review_reminder';

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TeacherRepository $teachers,
        private readonly AppSettingsInterface $settings,
        private readonly DocumentNextReviewReminderFinder $finder,
        private readonly DocumentReviewSchedule $schedule,
        private readonly NotificationMailer $mailer,
        private readonly NonWorkingDayChecker $nonWorkingDays,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {}

    public function __invoke(SendDocumentNextReviewReminderMessage $message): void
    {
        $today = $this->clock->now()->setTime(0, 0);
        foreach ($this->centres->findAllWithActiveYear() as $centre) {
            $year = $centre->getActiveAcademicYear();
            if ($year === null || !$this->isFirstWorkingDayOfTheWeek($year, $today)) {
                continue;
            }

            foreach ($this->recipients($centre, $year) as $teacher) {
                $this->remindTeacher($teacher, $centre);
            }
        }
    }

    private function isFirstWorkingDayOfTheWeek(AcademicYear $year, \DateTimeImmutable $today): bool
    {
        if ($this->nonWorkingDays->isNonWorkingDay($year, $today)) {
            return false;
        }

        for ($day = $today->modify('monday this week'); $day < $today; $day = $day->modify('+1 day')) {
            if (!$this->nonWorkingDays->isNonWorkingDay($year, $day)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The active year's teachers, plus the centre's quality managers (who may not teach that year
     * but get the reminders for folders with no responsible profile).
     *
     * @return list<Teacher>
     */
    private function recipients(EducationalCentre $centre, AcademicYear $year): array
    {
        $recipients = [];
        foreach ([...$this->teachers->findByAcademicYearOrderedByName($year), ...$centre->getQualityManagers()->toArray()] as $teacher) {
            $recipients[$teacher->getId()->toRfc4122()] = $teacher;
        }

        return array_values($recipients);
    }

    private function remindTeacher(Teacher $teacher, EducationalCentre $centre): void
    {
        // Only an explicit "off" disables it (the setting defaults to on).
        if ($teacher->getEmail() === null || !$teacher->isActive()
            || $this->settings->getForTeacherInCentre(self::ENABLED_SETTING, $teacher, $centre) === false
        ) {
            return;
        }

        $warningDays = $this->settings->getForTeacherInCentre(self::WARNING_DAYS_SETTING, $teacher, $centre);
        $documents   = $this->finder->forTeacher($teacher, $centre, is_int($warningDays) ? $warningDays : 30);
        if ($documents === []) {
            return;
        }

        $items = [];
        foreach ($documents as $document) {
            $folder  = $document->getFolder();
            $items[] = [
                'document' => $document,
                'state'    => $this->schedule->stateOf($document->getNextReviewAt()),
                'url'      => $this->urlGenerator->generate('app_document_tree', [
                    'section'   => $folder->getDocumentSection()->getId()->toRfc4122(),
                    'folder'    => $folder->getId()->toRfc4122(),
                    'highlight' => $document->getId()->toRfc4122(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        $this->mailer->send(
            $teacher,
            $centre,
            self::EVENT_KEY,
            $this->translator->trans('emails.document_next_review_reminder.subject', ['%count%' => \count($items)], 'emails'),
            $this->translator->trans('emails.document_next_review_reminder.heading', [], 'emails'),
            $this->twig->render('email/_document_next_review_reminder_body.html.twig', ['items' => $items]),
            $this->urlGenerator->generate('app_document_tree', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->translator->trans('emails.document_next_review_reminder.cta', [], 'emails'),
        );
    }
}
