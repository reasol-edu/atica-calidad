<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\FormModel;
use App\Repository\ActivityRepository;
use App\Repository\FormModelRevisionRepository;

/**
 * «Datos de otras entregas» of an activity: the tables of deliveries of its model (its revision in force), the activities each one can
 * read (those of the centre filled with the model the table asks for), and the checks of a choice — a source that ends after the
 * activity (a warning: some deliveries may still be missing) and one that, directly or through others, reads this activity (refused).
 */
final class ActivityDeliverySources
{
    public function __construct(
        private readonly FormModelRevisionRepository $modelRevisions,
        private readonly ActivityRepository $activities,
    ) {}

    /**
     * @return list<array{key: string, label: string, section: string, sourceModel: string}> the tables of deliveries of the model in force
     */
    public function tablesOf(?FormModel $model): array
    {
        $definition = $model === null ? [] : ($this->modelRevisions->findInForce($model)?->getDefinition() ?? []);
        $sectionOf  = [];
        foreach (FormDefinition::blocks($definition) as ['block' => $block, 'section' => $section]) {
            $sectionOf[FormDefinition::str($block['key'] ?? '')] = FormDefinition::str($section['title'] ?? '');
        }

        return array_map(static fn (array $block): array => [
            'key'         => FormDefinition::str($block['key'] ?? ''),
            'label'       => FormDefinition::str($block['label'] ?? ''),
            'section'     => $sectionOf[FormDefinition::str($block['key'] ?? '')] ?? '',
            'sourceModel' => FormDefinition::str($block['sourceModel'] ?? ''),
        ], FormDeliveredRows::tables($definition));
    }

    /**
     * The activities a table can read: those of the centre filled with $sourceModel (not $exceptId), by category and title.
     *
     * @return list<Activity>
     */
    public function choicesFor(EducationalCentre $centre, string $sourceModel, string $exceptId): array
    {
        $out = array_values(array_filter(
            $this->activities->findAllByCentre($centre, true),
            static fn (Activity $a): bool => $a->isForm() && $a->getFormModel()?->getId()->toRfc4122() === $sourceModel && $a->getId()->toRfc4122() !== $exceptId,
        ));
        usort($out, static fn (Activity $a, Activity $b): int => [mb_strtolower($a->getCategory()->getName()), mb_strtolower($a->getTitle())] <=> [mb_strtolower($b->getCategory()->getName()), mb_strtolower($b->getTitle())]);

        return $out;
    }

    /**
     * Whether $source, directly or through the activities it reads, reads the deliveries of the activity $targetId.
     *
     * @param array<string, true> $seen
     */
    public function readsFrom(Activity $source, string $targetId, array $seen = []): bool
    {
        $id = $source->getId()->toRfc4122();
        if ($id === $targetId) {
            return true;
        }
        if (isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;
        foreach ($source->getDeliverySources() as $next) {
            if ($this->readsFrom($next, $targetId, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a deadline (day/month) falls after another one in the academic year (September to August). */
    public static function endsAfter(int $day, int $month, int $otherDay, int $otherMonth): bool
    {
        $order = static fn (int $d, int $m): int => (($m + 3) % 12) * 100 + $d;

        return $order($day, $month) > $order($otherDay, $otherMonth);
    }
}
