<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Form\FormIndicatorCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Recalculates the indicators fed from delivered documents (frozen values and values typed by hand are left alone). Meant for cron. */
#[AsCommand(name: 'app:forms:calculate-indicators')]
class CalculateFormIndicatorsCommand extends Command
{
    public function __construct(
        private readonly FormIndicatorCalculator $calculator,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription($this->translator->trans('calculate_form_indicators.description', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success($this->translator->trans('calculate_form_indicators.done', ['%count%' => $this->calculator->recalculateAll()], 'command'));

        return Command::SUCCESS;
    }
}
