<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EducationalCentreRepository;
use App\Service\CentreProvisioner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:create-educational-centre')]
class CreateEducationalCentreCommand extends Command
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TranslatorInterface $translator,
        private readonly CentreProvisioner $centreProvisioner,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $t = fn(string $key) => $this->translator->trans($key, domain: 'command');

        $this
            ->setDescription($t('create_centre.description'))
            ->addArgument('code', InputArgument::OPTIONAL, $t('create_centre.argument.code'))
            ->addArgument('name', InputArgument::OPTIONAL, $t('create_centre.argument.name'))
            ->addArgument('city', InputArgument::OPTIONAL, $t('create_centre.argument.city'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $t  = fn(string $key, array $params = []) => $this->translator->trans($key, $params, 'command');

        $codeArg = $input->getArgument('code');
        $code    = is_string($codeArg) ? $codeArg : null;
        if ($code === null) {
            $asked = $io->ask($t('create_centre.ask.code'), validator: $this->notBlank());
            $code  = is_string($asked) ? $asked : '';
        }

        $nameArg = $input->getArgument('name');
        $name    = is_string($nameArg) ? $nameArg : null;
        if ($name === null) {
            $asked = $io->ask($t('create_centre.ask.name'), validator: $this->notBlank());
            $name  = is_string($asked) ? $asked : '';
        }

        $cityArg = $input->getArgument('city');
        $city    = is_string($cityArg) ? $cityArg : null;
        if ($city === null) {
            $asked = $io->ask($t('create_centre.ask.city'), validator: $this->notBlank());
            $city  = is_string($asked) ? $asked : '';
        }

        if ($this->centres->findByCode($code) !== null) {
            $io->error($t('create_centre.error.existing_code', ['%code%' => $code]));

            return Command::FAILURE;
        }

        $year = (int) $this->clock->now()->format('Y');
        $yearName = $year . '-' . ($year + 1);

        $this->centreProvisioner->provision($code, $name, $city, $yearName);

        $io->success($t('create_centre.success', ['%name%' => $name, '%code%' => $code, '%year%' => $yearName]));

        return Command::SUCCESS;
    }

    private function notBlank(): \Closure
    {
        $message = $this->translator->trans('field.required', domain: 'command');

        return static function (?string $value) use ($message): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException($message);
            }

            return $value;
        };
    }
}
