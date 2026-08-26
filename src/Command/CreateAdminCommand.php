<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:create-admin')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeacherRepository $teachers,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $t = fn(string $key) => $this->translator->trans($key, domain: 'command');

        $this
            ->setDescription($t('create_admin.description'))
            ->addArgument('username', InputArgument::REQUIRED, $t('create_admin.argument.username'))
            ->addArgument('password', InputArgument::OPTIONAL, $t('create_admin.argument.password'))
            ->addOption(
                'no-force-password-change',
                null,
                InputOption::VALUE_NONE,
                $t('create_admin.option.no_force_password_change'),
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $usernameArg = $input->getArgument('username');
        if (!is_string($usernameArg) || $usernameArg === '') {
            $io->error('Invalid username argument.');

            return Command::FAILURE;
        }
        $username = $usernameArg;

        $passwordArg = $input->getArgument('password');
        $password    = is_string($passwordArg) ? $passwordArg : null;
        if ($password === null) {
            $asked    = $io->askHidden($this->translator->trans('create_admin.argument.password', domain: 'command'));
            $password = is_string($asked) ? $asked : '';
        }

        if ($this->teachers->findByUsername($username) !== null) {
            $io->error($this->translator->trans(
                'create_admin.error.existing_user',
                ['%username%' => $username],
                'command',
            ));

            return Command::FAILURE;
        }

        $teacher = new Teacher(new PersonName('Admin', 'User'));
        $teacher->setUsername($username);
        $teacher->setPassword($this->passwordHasher->hashPassword($teacher, $password));
        $teacher->setAdmin(true);
        $teacher->setForcePasswordChange(!$input->getOption('no-force-password-change'));

        $this->em->persist($teacher);
        $this->em->flush();

        $io->success($this->translator->trans(
            'create_admin.success',
            ['%username%' => $username],
            'command',
        ));

        return Command::SUCCESS;
    }
}
