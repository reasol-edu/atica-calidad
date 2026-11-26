<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\ProfileAssignmentRow;
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
 * Read-only. Run it after updating to the version where the catalogue's elements are associated with elements of other lists instead
 * of «perfil › subperfil». An association used to name the profile too; now any profile on that list owes the element. That changes
 * something only in an activity whose folder accepts uploads from two profiles on the same list: this lists them, with the submissions
 * they would ask with one profile per list (the first by name, the one the old association most likely named) and the ones they ask
 * now. It never writes anything.
 */
#[AsCommand(name: 'app:list-items:links-report')]
class ListItemsLinksReportCommand extends Command
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly ListItemRepository $listItems,
        private readonly ActivityRepository $activities,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ListItemOwners $owners,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription($this->translator->trans('links_report.description', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        foreach ($this->centres->findAllOrderedByName() as $centre) {
            $io->section($centre->getName());

            $rows = [];
            foreach ($this->activities->findAllByCentre($centre, true) as $activity) {
                $listItem = $activity->getListItem();
                $folder   = $activity->getFolder();
                if ($listItem === null || $folder === null) {
                    continue;
                }

                $uploadRows = $this->access->getFolderUploadRows($folder);
                $shared     = self::profilesSharingAList($uploadRows);
                if ($shared === []) {
                    continue;
                }

                // With one profile per list: the rows of the other profiles of a shared list do not owe anything.
                $dropped = [];
                foreach ($shared as $profiles) {
                    foreach (array_slice($profiles, 1) as $profileId => $name) {
                        $dropped[$profileId] = true;
                    }
                }
                $single = array_values(array_filter($uploadRows, static fn (ProfileAssignmentRow $r): bool => !isset($dropped[$r->profile->getId()->toRfc4122()])));

                $before = 0;
                $after  = 0;
                foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
                    $before += count($this->owners->ownersOf($leaf, $single));
                    $after  += count($this->owners->ownersOf($leaf, $uploadRows));
                }
                $names = array_map(static fn (array $profiles): string => implode(', ', $profiles), $shared);
                $rows[] = [$activity->getTitle(), implode(' · ', $names), (string) $before, $after === $before ? (string) $after : $after . ' *'];
            }

            if ($rows === []) {
                $io->writeln($t('links_report.nothing'));

                continue;
            }

            $io->writeln($t('links_report.title'));
            $io->table([$t('links_report.col.activity'), $t('links_report.col.profiles'), $t('links_report.col.before'), $t('links_report.col.after')], $rows);
            $io->writeln($t('links_report.footnote'));
        }

        return Command::SUCCESS;
    }

    /**
     * The profiles of the folder's upload rows that take their subprofiles from the same list, for each list with two or more, by name.
     *
     * @param ProfileAssignmentRow[] $uploadRows
     *
     * @return list<array<string, string>> profile id => name, the first by name first
     */
    private static function profilesSharingAList(array $uploadRows): array
    {
        $byList = [];
        foreach ($uploadRows as $row) {
            $list = $row->profile->getListItem();
            if ($row->listItem === null || $list === null) {
                continue;
            }
            $byList[$list->getRoot()->getId()->toRfc4122()][$row->profile->getId()->toRfc4122()] = $row->profile->getName();
        }

        $shared = [];
        foreach ($byList as $profiles) {
            if (count($profiles) > 1) {
                asort($profiles, SORT_NATURAL | SORT_FLAG_CASE);
                $shared[] = $profiles;
            }
        }

        return $shared;
    }
}
