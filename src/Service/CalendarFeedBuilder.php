<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityObligationStatus;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A teacher's own deadlines as an iCalendar (RFC 5545) document, for a calendar app to subscribe
 * to: one all-day event per activity obligation still to do or not open yet, and per "Mejora
 * continua" task with a due date. Built from the same finders as "Tus próximos pasos", so the
 * calendar can't disagree with the dashboard. Each event's UID is stable (same obligation, same
 * occurrence), so a refresh updates events instead of duplicating them.
 */
final class CalendarFeedBuilder
{
    public function __construct(
        private readonly ActivityObligationFinder $obligations,
        private readonly QualityTaskFinder $qualityTasks,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {}

    public function build(Teacher $teacher, EducationalCentre $centre): string
    {
        $stamp = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ÁTICA Calidad//Calendario personal//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escape($this->translator->trans('feed.name', ['%centre%' => $centre->getName()], 'calendar')),
            'X-WR-TIMEZONE:Europe/Madrid',
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        foreach ($this->obligations->forTeacher($teacher, $centre) as $item) {
            if (!$this->isListed($item->status)) {
                continue;
            }
            $lines = [...$lines, ...$this->event($this->activityUid($item), $stamp, $item->deadline, $this->activitySummary($item), $item->categoryPath, $this->activityUrl($item))];
        }

        foreach ($this->qualityTasks->forTeacher($teacher, $centre) as $task) {
            if ($task->dueDate === null || $task->urgency === 'done') {
                continue;
            }
            $uid = implode('-', ['task', $task->type, $task->finding?->getId()->toRfc4122() ?? $task->action?->getId()->toRfc4122() ?? $task->indicator?->getId()->toRfc4122() ?? $task->audit?->getId()->toRfc4122() ?? 'x', $task->period?->getId()->toRfc4122() ?? '', $task->dueDate->format('Ymd')]);
            $lines = [...$lines, ...$this->event(
                $uid,
                $stamp,
                $task->dueDate,
                $this->translator->trans('feed.deadline', ['%title%' => $this->translator->trans('task.' . $task->type, [], 'quality') . ': ' . $task->label()], 'calendar'),
                $this->translator->trans('feed.quality', [], 'calendar'),
                null,
            )];
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    /** What still needs the teacher, or will once it opens: not done, not waiting for a reviewer, not closed. */
    private function isListed(ActivityObligationStatus $status): bool
    {
        return $status->isActionable() || $status->group() === ActivityObligationStatus::GROUP_UPCOMING;
    }

    private function activityUid(ActivityDashboardItem $item): string
    {
        return implode('-', ['activity', $item->activity->getId()->toRfc4122(), $item->profileId, $item->listItemId, $item->leafId, $item->deadline->format('Ymd')]);
    }

    private function activitySummary(ActivityDashboardItem $item): string
    {
        $title = $item->activity->getTitle() . ($item->ownerLabel !== null && $item->ownerLabel !== '' ? ' · ' . $item->ownerLabel : '');

        return $this->translator->trans('feed.deadline', ['%title%' => $title], 'calendar');
    }

    private function activityUrl(ActivityDashboardItem $item): string
    {
        return $this->urlGenerator->generate('app_activities', $item->linkParams(), UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @return list<string>
     */
    private function event(string $uid, string $stamp, \DateTimeImmutable $day, string $summary, string $description, ?string $url): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $uid . '@atica-calidad',
            'DTSTAMP:' . $stamp,
            'DTSTART;VALUE=DATE:' . $day->format('Ymd'),
            'DTEND;VALUE=DATE:' . $day->modify('+1 day')->format('Ymd'),
            'SUMMARY:' . $this->escape($summary),
            'DESCRIPTION:' . $this->escape($description),
            'TRANSP:TRANSPARENT',
        ];
        if ($url !== null) {
            $lines[] = 'URL:' . $url;
        }
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** Content lines are at most 75 octets; the rest continues on the next line after a space (never inside a UTF-8 character). */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out   = '';
        $chunk = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($chunk) + strlen($char) > $limit) {
                $out .= $chunk . "\r\n ";
                $chunk = '';
                $limit = 74;
            }
            $chunk .= $char;
        }

        return $out . $chunk;
    }
}
