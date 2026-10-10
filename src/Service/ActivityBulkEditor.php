<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityBulkEditPlan;
use App\Model\ActivityBulkEditRow;
use App\Repository\ActivityCategoryRepository;
use App\Repository\ActivityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Edits one setting of several activities at once — move them to another category, hide or show
 * them, set their general deadline, make them required or optional. A plan is built first (what each
 * selected activity would change from and to, for the preview) and applied only on confirmation;
 * applying leaves each activity's history entry (ActivityChangeRecorder), like any single edit.
 */
final class ActivityBulkEditor
{
    public const string MOVE       = 'move';
    public const string VISIBILITY = 'visibility';
    public const string DEADLINE   = 'deadline';
    public const string REQUIRED   = 'required';

    public const array ACTIONS = [self::MOVE, self::VISIBILITY, self::DEADLINE, self::REQUIRED];

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivityCategoryRepository $categories,
        private readonly ActivityChangeRecorder $changes,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @param array<string, mixed> $input   the form's fields: category, visibility, startDay/startMonth/endDay/endMonth, required
     * @param list<string>         $activityIds
     *
     * @return ActivityBulkEditPlan|string the plan, or the translation key (domain activity_content) of what is wrong
     */
    public function plan(EducationalCentre $centre, string $action, array $input, array $activityIds): ActivityBulkEditPlan|string
    {
        if (!\in_array($action, self::ACTIONS, true)) {
            return 'bulk.error.action';
        }

        $selected = $this->selected($centre, $activityIds);
        if ($selected === []) {
            return 'bulk.error.none_selected';
        }

        $params = $this->params($centre, $action, $input);
        if (\is_string($params)) {
            return $params;
        }

        // The same for every row: resolved once, not once per activity.
        $after = $this->describeAfter($centre, $action, $params);
        $rows  = [];
        foreach ($selected as $activity) {
            $rows[] = new ActivityBulkEditRow($activity, $this->describe($action, $activity), $after);
        }

        return new ActivityBulkEditPlan($action, $params, $rows);
    }

    /** Applies the plan's changes and flushes; returns how many activities actually changed. */
    public function apply(EducationalCentre $centre, ActivityBulkEditPlan $plan, ?Teacher $author): int
    {
        $target = $plan->action === self::MOVE ? $this->categories->findByIdAndCentre($plan->params['category'], $centre) : null;
        if ($plan->action === self::MOVE && $target === null) {
            return 0;
        }

        // Only the setting being edited is read before and after: the others' lazy relations stay unread.
        $tracked   = [self::MOVE => 'category', self::VISIBILITY => 'hidden', self::DEADLINE => 'deadline', self::REQUIRED => 'required'][$plan->action];
        $nextPosition = $target !== null ? $this->activities->nextPosition($target) : 0;
        $changed   = 0;
        foreach ($plan->rows as $row) {
            if (!$row->changes()) {
                continue;
            }

            $activity = $row->activity;
            $before   = $this->changes->snapshot($activity, [$tracked]);
            match ($plan->action) {
                self::MOVE       => $activity->setCategory($target)->setPosition($nextPosition++),
                self::VISIBILITY => $activity->setHidden($plan->params['visibility'] === 'hidden'),
                self::DEADLINE   => $activity->setStart((int) $plan->params['startDay'], (int) $plan->params['startMonth'])
                    ->setEnd((int) $plan->params['endDay'], (int) $plan->params['endMonth']),
                default          => $activity->setRequired($plan->params['required'] === 'required'),
            };
            $this->changes->recordUpdated($activity, $before, $author);
            ++$changed;
        }
        $this->em->flush();

        return $changed;
    }

    /** @return list<ActivityCategory> */
    public function categoryChoices(EducationalCentre $centre): array
    {
        $all = $this->categories->findAllByCentre($centre);
        usort($all, fn (ActivityCategory $a, ActivityCategory $b): int => strcmp($this->path($a), $this->path($b)));

        return $all;
    }

    public function path(ActivityCategory $category): string
    {
        $trail = [];
        for ($node = $category; $node !== null; $node = $node->getParent()) {
            array_unshift($trail, $node->getName());
        }

        return implode(' › ', $trail);
    }

    /**
     * @param  list<string> $ids
     * @return list<Activity>
     */
    private function selected(EducationalCentre $centre, array $ids): array
    {
        $wanted = array_flip(array_filter($ids, '\is_string'));
        if ($wanted === []) {
            return [];
        }

        // Every activity of the centre (hidden included) is read once: ids from elsewhere simply never match.
        return array_values(array_filter(
            $this->activities->findAllByCentre($centre, true),
            static fn (Activity $a): bool => isset($wanted[$a->getId()->toRfc4122()]),
        ));
    }

    /**
     * @param  array<string, mixed>        $input
     * @return array<string, string>|string
     */
    private function params(EducationalCentre $centre, string $action, array $input): array|string
    {
        switch ($action) {
            case self::MOVE:
                $id = \is_string($input['category'] ?? null) ? $input['category'] : '';
                if ($id === '' || $this->categories->findByIdAndCentre($id, $centre) === null) {
                    return 'bulk.error.category';
                }

                return ['category' => $id];
            case self::VISIBILITY:
                $value = $input['visibility'] ?? '';

                return \in_array($value, ['hidden', 'visible'], true) ? ['visibility' => $value] : 'bulk.error.visibility';
            case self::REQUIRED:
                $value = $input['required'] ?? '';

                return \in_array($value, ['required', 'optional'], true) ? ['required' => $value] : 'bulk.error.required';
            default:
                $numbers = [];
                foreach (['startDay' => 31, 'startMonth' => 12, 'endDay' => 31, 'endMonth' => 12] as $field => $max) {
                    $raw = $input[$field] ?? '';
                    if (!\is_string($raw) || !ctype_digit($raw) || (int) $raw < 1 || (int) $raw > $max) {
                        return 'bulk.error.deadline';
                    }
                    $numbers[$field] = (string) (int) $raw;
                }

                return $numbers;
        }
    }

    private function describe(string $action, Activity $activity): string
    {
        return match ($action) {
            self::MOVE       => $this->path($activity->getCategory()),
            self::VISIBILITY => $this->word($activity->isHidden() ? 'hidden' : 'visible'),
            self::DEADLINE   => sprintf('%d/%d – %d/%d', $activity->getStartDay(), $activity->getStartMonth(), $activity->getEndDay(), $activity->getEndMonth()),
            default          => $this->word($activity->isRequired() ? 'required' : 'optional'),
        };
    }

    /** @param array<string, string> $params */
    private function describeAfter(EducationalCentre $centre, string $action, array $params): string
    {
        return match ($action) {
            self::MOVE       => ($target = $this->categories->findByIdAndCentre($params['category'], $centre)) !== null ? $this->path($target) : '',
            self::VISIBILITY => $this->word($params['visibility']),
            self::DEADLINE   => sprintf('%d/%d – %d/%d', $params['startDay'], $params['startMonth'], $params['endDay'], $params['endMonth']),
            default          => $this->word($params['required']),
        };
    }

    private function word(string $key): string
    {
        return $this->translator->trans('bulk.value.' . $key, [], 'activity_content');
    }
}
