<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminCommandTest extends RepositoryTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $command     = $application->find('app:create-admin');
        $this->tester = new CommandTester($command);
    }

    private function teachers(): TeacherRepository
    {
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);

        return $teachers;
    }

    public function testCreatesAnAdminWithAHashedPasswordAndForcedPasswordChangeByDefault(): void
    {
        $this->tester->execute(['username' => 'admin', 'password' => 'S3cret!Password']);

        self::assertSame(0, $this->tester->getStatusCode());

        $this->em->clear();
        $teacher = $this->teachers()->findByUsername('admin');
        self::assertNotNull($teacher);
        self::assertTrue($teacher->isAdmin());
        self::assertTrue($teacher->isForcePasswordChange());
        self::assertNotSame('S3cret!Password', $teacher->getPassword());

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($teacher, 'S3cret!Password'));
    }

    public function testNoForcePasswordChangeOptionDisablesTheForcedChangeFlag(): void
    {
        $this->tester->execute([
            'username' => 'admin',
            'password' => 'S3cret!Password',
            '--no-force-password-change' => true,
        ]);

        $this->em->clear();
        $teacher = $this->teachers()->findByUsername('admin');
        self::assertNotNull($teacher);
        self::assertFalse($teacher->isForcePasswordChange());
    }

    public function testFailsWhenTheUsernameAlreadyExists(): void
    {
        $existing = (new Teacher(new PersonName('Ya', 'Existe')))->setUsername('admin');
        $this->persist($existing);

        $this->tester->execute(['username' => 'admin', 'password' => 'S3cret!Password']);

        self::assertSame(1, $this->tester->getStatusCode());

        $this->em->clear();
        self::assertCount(1, $this->teachers()->findAll());
    }
}
