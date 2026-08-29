<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateEducationalCentreCommandTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $command     = $application->find('app:create-educational-centre');
        $this->tester = new CommandTester($command);
    }

    private function centres(): EducationalCentreRepository
    {
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);

        return $centres;
    }

    public function testCreatesACentreWithAnActiveAcademicYearNamedFromTheCurrentYear(): void
    {
        self::mockTime('2025-09-01 10:00:00');

        $this->tester->execute(['code' => '12345678', 'name' => 'IES Ejemplo', 'city' => 'Sevilla']);

        self::assertSame(0, $this->tester->getStatusCode());

        $this->em->clear();
        $centre = $this->centres()->findByCode('12345678');
        self::assertNotNull($centre);
        self::assertSame('IES Ejemplo', $centre->getName());
        self::assertSame('Sevilla', $centre->getCity());
        $year = $centre->getActiveAcademicYear();
        self::assertNotNull($year);
        self::assertSame('2025-2026', $year->getName());
    }

    public function testFailsWhenTheCodeAlreadyExists(): void
    {
        $existing = (new EducationalCentre())->setCode('12345678')->setName('Otro')->setCity('Otra ciudad');
        $this->persist($existing);

        $this->tester->execute(['code' => '12345678', 'name' => 'IES Ejemplo', 'city' => 'Sevilla']);

        self::assertSame(1, $this->tester->getStatusCode());
    }
}
