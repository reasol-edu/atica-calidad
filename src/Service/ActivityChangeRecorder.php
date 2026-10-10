<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityChange;
use App\Entity\Teacher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Keeps an activity's history (ActivityChange): a readable snapshot of its settings before an
 * edit, compared with the one after it, gives the list of what changed. Values are stored as plain
 * text — booleans as "1"/"0", the scope as its enum value — and given their words at display time.
 * Nothing is flushed here: the caller's own flush writes the entry together with the change.
 *
 * A snapshot can be limited to some settings, so an edit that only touches one (a bulk edit)
 * doesn't read the lazy relations of the others for every activity.
 */
final class ActivityChangeRecorder
{
    /** Every setting that is tracked, in display order. */
    public const array FIELDS = [
        'title', 'description', 'category', 'deadline', 'element_deadlines', 'list_item', 'tags', 'folder', 'scope',
        'required', 'hidden', 'auto_complete', 'start_enforced', 'end_enforced', 'grace_days', 'prefix', 'general', 'related_documents',
    ];

    /** Fields shown as yes/no. */
    public const array BOOLEAN_FIELDS = ['required', 'hidden', 'auto_complete', 'start_enforced', 'end_enforced', 'general'];

    private const int TEXT_LIMIT = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param  list<string>|null     $fields the settings to read (all by default)
     * @return array<string, string> setting => value, in display order
     */
    public function snapshot(Activity $activity, ?array $fields = null): array
    {
        $snapshot = [];
        foreach (self::FIELDS as $field) {
            if ($fields === null || \in_array($field, $fields, true)) {
                $snapshot[$field] = $this->value($activity, $field);
            }
        }

        return $snapshot;
    }

    public function recordCreated(Activity $activity, ?Teacher $author): void
    {
        $this->em->persist(new ActivityChange($activity, $author, $this->clock->now(), ActivityChange::CREATED));
    }

    public function recordDuplicated(Activity $copy, Activity $source, ?Teacher $author): void
    {
        $this->em->persist(new ActivityChange($copy, $author, $this->clock->now(), ActivityChange::DUPLICATED, [
            ['field' => 'duplicated_from', 'from' => '', 'to' => $source->getTitle()],
        ]));
    }

    /**
     * Records what differs between $before (a snapshot() taken ahead of the edit, of the same
     * settings) and now; nothing when nothing changed.
     *
     * @param array<string, string> $before
     */
    public function recordUpdated(Activity $activity, array $before, ?Teacher $author): void
    {
        $changes = [];
        foreach ($this->snapshot($activity, array_keys($before)) as $field => $after) {
            if ($before[$field] !== $after) {
                $changes[] = ['field' => $field, 'from' => $before[$field], 'to' => $after];
            }
        }

        if ($changes !== []) {
            $this->em->persist(new ActivityChange($activity, $author, $this->clock->now(), ActivityChange::UPDATED, $changes));
        }
    }

    private function value(Activity $activity, string $field): string
    {
        return match ($field) {
            'title'             => $activity->getTitle(),
            'description'       => mb_strimwidth(trim((string) $activity->getDescription()), 0, self::TEXT_LIMIT, '…'),
            'category'          => $activity->getCategory()->getName(),
            'deadline'          => sprintf('%d/%d – %d/%d', $activity->getStartDay(), $activity->getStartMonth(), $activity->getEndDay(), $activity->getEndMonth()),
            'element_deadlines' => (string) $activity->getListItemDeadlines()->count(),
            'list_item'         => $activity->getListItem()?->getName() ?? '',
            'tags'              => $this->tagNames($activity),
            'folder'            => $activity->getFolder()?->getName() ?? '',
            'scope'             => $activity->getSubmissionScope()->value,
            'required'          => $activity->isRequired() ? '1' : '0',
            'hidden'            => $activity->isHidden() ? '1' : '0',
            'auto_complete'     => $activity->isAutoComplete() ? '1' : '0',
            'start_enforced'    => $activity->isStartDateEnforced() ? '1' : '0',
            'end_enforced'      => $activity->isEndDateEnforced() ? '1' : '0',
            'grace_days'        => (string) $activity->getEndDateGraceDays(),
            'prefix'            => $activity->getSubmissionPrefix() ?? '',
            'general'           => $activity->isGeneral() ? '1' : '0',
            default             => (string) $activity->getRelatedDocuments()->count(),
        };
    }

    private function tagNames(Activity $activity): string
    {
        $tags = array_map(static fn ($tag): string => $tag->getName(), $activity->getTags()->toArray());
        sort($tags);

        return implode(', ', $tags);
    }
}
