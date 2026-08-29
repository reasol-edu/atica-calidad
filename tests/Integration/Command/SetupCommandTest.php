<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends RepositoryTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $command     = $application->find('app:setup');
        $this->tester = new CommandTester($command);
    }

    private function teachers(): TeacherRepository
    {
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);

        return $teachers;
    }

    public function testCreatesAnAdminUserWhenNoTeachersExistYet(): void
    {
        $this->tester->execute([]);

        self::assertSame(0, $this->tester->getStatusCode());

        $this->em->clear();
        $admin = $this->teachers()->findByUsername('admin');
        self::assertNotNull($admin);
        self::assertTrue($admin->isAdmin());
        self::assertTrue($admin->isForcePasswordChange());
    }

    public function testSkipsWithoutErrorWhenATeacherAlreadyExists(): void
    {
        $existing = (new Teacher(new PersonName('Ya', 'Existe')))->setUsername('alguien');
        $this->persist($existing);

        $this->tester->execute([]);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Se omite la inicialización', $this->tester->getDisplay());

        $this->em->clear();
        self::assertCount(1, $this->teachers()->findAll());
    }

    public function testDemoDataOptionProvisionsATestCentre(): void
    {
        $this->tester->execute(['--demo-data' => true]);

        self::assertSame(0, $this->tester->getStatusCode());

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        self::assertNotNull($centres->findByCode('23999999'));
    }

    public function testNoForcePasswordChangeOptionDisablesTheForcedChangeFlag(): void
    {
        $this->tester->execute(['--no-force-password-change' => true]);

        $this->em->clear();
        $admin = $this->teachers()->findByUsername('admin');
        self::assertNotNull($admin);
        self::assertFalse($admin->isForcePasswordChange());
    }
}
