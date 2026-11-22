<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ActivityRepository;
use App\Repository\EducationalCentreRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\Form\FormSubmissionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates the draft of every slot of the activities filled in online that ask for everybody's draft, once their period has opened.
 * Meant to run daily (cron): a slot that already has its draft is left alone, so it can run as often as wanted.
 */
#[AsCommand(name: 'app:create-form-drafts')]
class CreateFormDraftsCommand extends Command
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly ActivityRepository $activities,
        private readonly ActivityDeadlineChecker $deadlines,
        private readonly FormSubmissionService $forms,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription($this->translator->trans('create_form_drafts.description', domain: 'command'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $created = 0;
        foreach ($this->centres->findAll() as $centre) {
            foreach ($this->activities->findAllByCentre($centre, true) as $activity) {
                if ($activity->isForm() && $activity->isFormDraftsForAll() && $this->deadlines->hasStarted($activity)) {
                    $created += $this->forms->createMissingDrafts($activity);
                }
            }
        }
        $io->success($this->translator->trans('create_form_drafts.done', ['%count%' => $created], 'command'));

        return Command::SUCCESS;
    }
}
