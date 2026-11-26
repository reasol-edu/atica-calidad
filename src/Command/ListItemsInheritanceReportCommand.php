<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Activity;
use App\Entity\ListItem;
use App\Model\ListItemAssociation;
use App\Repository\ActivityRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\ListItemRepository;
use App\Service\DocumentTreeAccessChecker;
use App\Service\ListItemOwners;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-only. Run it right after updating to the version where the associations of the catalogue
 * accumulate down a branch (an element has its own plus those of all its ancestors, instead of only
 * its own), and before any submission is generated from the new rule. It computes the former rule
 * apart and says which elements now have more associations, which own associations are now covered
 * by a broader inherited one, and how many submissions each activity that works on a list element
 * had and has now. It never writes anything.
 */
#[AsCommand(name: 'app:list-items:inheritance-report')]
class ListItemsInheritanceReportCommand extends Command
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly ListItemRepository $listItems,
        private readonly ActivityRepository $activities,
        private readonly ListItemOwners $owners,
        private readonly DocumentTreeAccessChecker $access,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription($this->translator->trans('inheritance_report.description', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        foreach ($this->centres->findAllOrderedByName() as $centre) {
            $io->section($centre->getName());

            // Every element whose effective associations differ between the former rule and the new one,
            // and apart those with an own association that used to narrow what they inherit and is now covered.
            $changed = [];
            $covered = [];
            foreach ($this->listItems->findAllByCentre($centre) as $item) {
                $before = $this->formerAssociations($item);
                $known  = [];
                foreach ($before as $association) {
                    $known[$association->key()] = true;
                }
                $added = [];
                foreach ($item->getInheritedAssociations() as $pair) {
                    if (!isset($known[$pair['association']->key()])) {
                        $added[] = $pair['association']->label().' ('.$pair['item']->getName().')';
                    }
                }
                if ($added !== []) {
                    $changed[] = [$this->path($item), implode(', ', array_map(static fn (ListItemAssociation $a): string => $a->label(), $before)), implode(', ', $added)];
                }

                foreach ($item->getAssociations() as $own) {
                    $covering = $item->getAncestorCovering($own);
                    if ($covering !== null && !$covering['exact']) {
                        $covered[] = [$this->path($item), $own->label(), $covering['association']->label().' ('.$covering['item']->getName().')'];
                    }
                }
            }
            usort($changed, static fn (array $a, array $b): int => strnatcasecmp($a[0], $b[0]));
            usort($covered, static fn (array $a, array $b): int => strnatcasecmp($a[0], $b[0]));

            if ($changed === []) {
                $io->writeln($t('inheritance_report.no_items'));
            } else {
                $io->writeln($t('inheritance_report.items_title'));
                $io->table([$t('inheritance_report.col.item'), $t('inheritance_report.col.former'), $t('inheritance_report.col.added')], $changed);
            }
            if ($covered !== []) {
                $io->writeln($t('inheritance_report.covered_title'));
                $io->table([$t('inheritance_report.col.item'), $t('inheritance_report.col.own'), $t('inheritance_report.col.covered_by')], $covered);
            }

            $activityRows = [];
            foreach ($this->activities->findAllByCentre($centre, true) as $activity) {
                $row = $this->countFor($activity);
                if ($row !== null) {
                    $activityRows[] = $row;
                }
            }

            if ($activityRows === []) {
                $io->writeln($t('inheritance_report.no_activities'));

                continue;
            }

            $io->writeln($t('inheritance_report.activities_title'));
            $io->table(
                [$t('inheritance_report.col.activity'), $t('inheritance_report.col.before'), $t('inheritance_report.col.after')],
                array_map(static fn (array $r): array => [$r['title'], (string) $r['before'], $r['after'] === $r['before'] ? (string) $r['after'] : $r['after'].' *'], $activityRows),
            );
            if (array_filter($activityRows, static fn (array $r): bool => $r['after'] !== $r['before']) !== []) {
                $io->writeln($t('inheritance_report.footnote'));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{title: string, before: int, after: int}|null null for an activity that is not worked on a list element
     */
    private function countFor(Activity $activity): ?array
    {
        $listItem = $activity->getListItem();
        $folder   = $activity->getFolder();
        if ($listItem === null || $folder === null) {
            return null;
        }

        $uploadRows = $this->access->getFolderUploadRows($folder);
        $before     = 0;
        $after      = 0;
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            $before += count($this->owners->forAssociations($this->formerAssociations($leaf), $uploadRows));
            $after  += count($this->owners->ownersOf($leaf, $uploadRows));
        }

        return ['title' => $activity->getTitle(), 'before' => $before, 'after' => $after];
    }

    /**
     * The former rule, calculated apart: the associations of the item itself or, with none, of its
     * closest ancestor that has some.
     *
     * @return list<ListItemAssociation>
     */
    private function formerAssociations(ListItem $leaf): array
    {
        $seen = [];
        for ($node = $leaf; $node !== null && !isset($seen[spl_object_id($node)]); $node = $node->getParent()) {
            $seen[spl_object_id($node)] = true;
            if ($node->isAssociated()) {
                return $node->getAssociations();
            }
        }

        return [];
    }

    private function path(ListItem $item): string
    {
        $names = [];
        $seen  = [];
        for ($node = $item; $node !== null && !isset($seen[spl_object_id($node)]); $node = $node->getParent()) {
            $seen[spl_object_id($node)] = true;
            array_unshift($names, $node->getName());
        }

        return implode(' › ', $names);
    }
}
