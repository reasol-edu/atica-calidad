<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FormSubmissionRevisionRepository;
use App\Service\Form\FormValueIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Rebuilds the FormValue index (what indicators and the explorer read) from the frozen data of every delivered revision. */
#[AsCommand(name: 'app:forms:reindex')]
class ReindexFormValuesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormSubmissionRevisionRepository $revisions,
        private readonly FormValueIndexer $indexer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription($this->translator->trans('reindex_form_values.description', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $total = 0;
        $n     = 0;
        // One revision at a time and clearing: a revision holds its whole data, so never many in memory.
        foreach ($this->revisions->findAllIds() as $id) {
            $revision = $this->revisions->findOneById($id);
            if ($revision === null) {
                continue;
            }
            $total += $this->indexer->index($revision);
            $this->em->flush();
            $this->em->clear();
            ++$n;
        }
        $io->success($this->translator->trans('reindex_form_values.done', ['%revisions%' => $n, '%values%' => $total], 'command'));

        return Command::SUCCESS;
    }
}
