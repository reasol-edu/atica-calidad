<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\TextIndexStatus;
use App\Repository\DocumentFileTextRepository;
use App\Service\DocumentTextIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads the text of every stored file that is not in the search yet, in this process, until none is left. The worker does the
 * same in small batches on its own, so this is for a first load that should not wait for it, or to start again from nothing.
 */
#[AsCommand(name: 'app:index-documents')]
class IndexDocumentsCommand extends Command
{
    public function __construct(
        private readonly DocumentTextIndexer $indexer,
        private readonly DocumentFileTextRepository $texts,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $t = fn (string $key): string => $this->translator->trans($key, domain: 'command');

        $this
            ->setDescription($t('index_documents.description'))
            ->addOption('reindex', null, InputOption::VALUE_NONE, $t('index_documents.option.reindex'))
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, $t('index_documents.option.retry_failed'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'command');

        if ($input->getOption('reindex') === true) {
            $this->texts->deleteAll();
        } elseif ($input->getOption('retry-failed') === true) {
            $this->texts->deleteByStatus(TextIndexStatus::Failed);
        }

        $total = 0;
        while (($read = $this->indexer->sweep(DocumentTextIndexer::SWEEP_FILES, 3600)) > 0) {
            $total += $read;
            $io->writeln($t('index_documents.progress', ['%count%' => $total]));
        }

        $io->success($t('index_documents.done', ['%count%' => $total]));

        return Command::SUCCESS;
    }
}
