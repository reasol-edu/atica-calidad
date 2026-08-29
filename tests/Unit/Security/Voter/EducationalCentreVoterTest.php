<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Security\Voter\EducationalCentreVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class EducationalCentreVoterTest extends TestCase
{
    private EducationalCentreVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new EducationalCentreVoter();
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function tokenFor(?Teacher $teacher): TokenInterface
    {
        $token = self::createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($teacher);

        return $token;
    }

    private function vote(?Teacher $teacher, string $attribute, EducationalCentre $centre): int
    {
        return $this->voter->vote($this->tokenFor($teacher), $centre, [$attribute]);
    }

    public function testSupportsOnlyItsOwnAttributesAndEducationalCentreSubjects(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($this->tokenFor($teacher), $centre, ['unknown.attribute']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($this->tokenFor($teacher), new \stdClass(), [EducationalCentreVoter::SECTION]));
    }

    public function testDeniesAnonymousUser(): void
    {
        $centre = $this->centre();
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, EducationalCentreVoter::SECTION, $centre));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, EducationalCentreVoter::RESPONSIBILITIES, $centre));
    }

    public function testGlobalAdminGrantedBoth(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('admin');
        $admin->setAdmin(true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, EducationalCentreVoter::SECTION, $centre));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, EducationalCentreVoter::RESPONSIBILITIES, $centre));
    }

    public function testCentreAdminGrantedBoth(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('director');
        $centre->getAdmins()->add($teacher);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, EducationalCentreVoter::SECTION, $centre));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, EducationalCentreVoter::RESPONSIBILITIES, $centre));
    }

    /** Quality managers get the broader RESPONSIBILITIES grant, but not SECTION — the centre-management screen stays admin-only. */
    public function testQualityManagerGrantedOnlyResponsibilities(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('calidad');
        $centre->getQualityManagers()->add($teacher);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, EducationalCentreVoter::SECTION, $centre));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, EducationalCentreVoter::RESPONSIBILITIES, $centre));
    }

    public function testPlainTeacherDeniedBoth(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, EducationalCentreVoter::SECTION, $centre));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, EducationalCentreVoter::RESPONSIBILITIES, $centre));
    }

    /** Being an admin of a DIFFERENT centre must never grant access to this one. */
    public function testAdminOfAnotherCentreIsDenied(): void
    {
        $centreA = $this->centre();
        $centreB = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Otra ciudad');
        $teacher = $this->teacher('director_b');
        $centreB->getAdmins()->add($teacher);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, EducationalCentreVoter::SECTION, $centreA));
    }
}
