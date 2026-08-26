<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Service\CentreProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:setup')]
class SetupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeacherRepository $teachers,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
        private readonly CentreProvisioner $centreProvisioner,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->translator->trans('setup.description', domain: 'command'))
            ->addOption(
                'no-force-password-change',
                null,
                InputOption::VALUE_NONE,
                $this->translator->trans('setup.option.no_force_password_change', domain: 'command'),
            )
            ->addOption(
                'demo-data',
                null,
                InputOption::VALUE_NONE,
                $this->translator->trans('setup.option.demo_data', domain: 'command'),
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn(string $key) => $this->translator->trans($key, domain: 'command');

        if ($this->teachers->countAll() > 0) {
            $io->note($t('setup.skipped'));

            return Command::SUCCESS;
        }

        $demoData = (bool) $input->getOption('demo-data');

        if ($demoData) {
            $year     = (int) $this->clock->now()->format('Y');
            $yearName = $year . '-' . ($year + 1);

            $this->centreProvisioner->provision('23999999', 'IES Test', 'Linares', $yearName);
        }

        $teacher = new Teacher(new PersonName('Admin', 'User'));
        $teacher->setUsername('admin');
        $teacher->setPassword($this->passwordHasher->hashPassword($teacher, 'admin'));
        $teacher->setAdmin(true);
        $teacher->setForcePasswordChange(!$input->getOption('no-force-password-change'));

        $this->em->persist($teacher);
        $this->em->flush();

        $io->success($t($demoData ? 'setup.success_demo_data' : 'setup.success'));

        return Command::SUCCESS;
    }
}
