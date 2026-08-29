<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class EmailVerificationControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testValidTokenVerifiesTheEmail(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $plainToken = 'token-valido';
        $teacher
            ->setEmail('viejo@example.com')
            ->setPendingEmail('nuevo@example.com')
            ->setEmailVerificationToken($plainToken)
            ->setEmailVerificationTokenExpiresAt(\Symfony\Component\Clock\now()->modify('+1 hour'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/perfil/verificar-email/{$plainToken}");

        self::assertTrue($this->client->getResponse()->isRedirect('/perfil'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertSame('nuevo@example.com', $reloaded->getEmail());
        self::assertNull($reloaded->getPendingEmail());
        self::assertNull($reloaded->getEmailVerificationToken());
    }

    public function testUnknownTokenLeavesEmailUnchanged(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $teacher->setEmail('viejo@example.com');
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/perfil/verificar-email/no-existe');

        self::assertTrue($this->client->getResponse()->isRedirect('/perfil'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertSame('viejo@example.com', $reloaded->getEmail());
    }

    public function testExpiredTokenClearsThePendingEmailWithoutVerifying(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $plainToken = 'token-caducado';
        $teacher
            ->setEmail('viejo@example.com')
            ->setPendingEmail('nuevo@example.com')
            ->setEmailVerificationToken($plainToken);
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        self::mockTime('2024-01-01 10:00:00');
        $teacher->setEmailVerificationTokenExpiresAt(\Symfony\Component\Clock\now()->modify('-1 hour'));
        $this->flush();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/perfil/verificar-email/{$plainToken}");

        self::assertTrue($this->client->getResponse()->isRedirect('/perfil'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertSame('viejo@example.com', $reloaded->getEmail(), 'an expired token must never verify the pending email');
        self::assertNull($reloaded->getPendingEmail());
        self::assertNull($reloaded->getEmailVerificationToken());
    }
}
