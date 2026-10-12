<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Tests\Integration\RepositoryTestCase;

/**
 * Who may work in which centre — the rule TenantContext re-checks on every request, so it has to
 * stay one cheap query and give the same answer through the list and the single-centre check.
 */
final class EducationalCentreRepositoryAccessTest extends RepositoryTestCase
{
    private EducationalCentreRepository $centres;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var EducationalCentreRepository $centres */
        $centres       = self::getContainer()->get(EducationalCentreRepository::class);
        $this->centres = $centres;
    }

    private function teacher(string $username, bool $admin = false): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username)->setAdmin($admin);
    }

    private function centre(string $code, string $name): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName($name)->setCity('Ciudad');
    }

    public function testAGlobalAdminReachesEveryCentre(): void
    {
        $a     = $this->centre('11111111', 'Centro A');
        $b     = $this->centre('22222222', 'Centro B');
        $admin = $this->teacher('admin', true);
        $this->persist($a, $b, $admin);

        self::assertSame(['Centro A', 'Centro B'], array_map(static fn (EducationalCentre $c): string => $c->getName(), $this->centres->findAccessibleByTeacher($admin)));
        self::assertTrue($this->centres->isAccessibleByTeacher($a, $admin));
        self::assertTrue($this->centres->isAccessibleByTeacher($b, $admin));
    }

    public function testACentreAdminAndATeacherOfOneOfItsYearsReachTheirCentreOnly(): void
    {
        $a        = $this->centre('11111111', 'Centro A');
        $b        = $this->centre('22222222', 'Centro B');
        $centreAdmin = $this->teacher('directora');
        $a->addAdmin($centreAdmin);
        $teacher  = $this->teacher('docente');
        $oldYear  = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($b)->addTeacher($teacher);
        $stranger = $this->teacher('ajeno');
        $this->persist($a, $b, $centreAdmin, $teacher, $oldYear, $stranger);

        self::assertSame(['Centro A'], array_map(static fn (EducationalCentre $c): string => $c->getName(), $this->centres->findAccessibleByTeacher($centreAdmin)));
        self::assertTrue($this->centres->isAccessibleByTeacher($a, $centreAdmin));
        self::assertFalse($this->centres->isAccessibleByTeacher($b, $centreAdmin));

        // A plain teacher belongs to a centre by being listed in one of its years, whichever year.
        self::assertSame(['Centro B'], array_map(static fn (EducationalCentre $c): string => $c->getName(), $this->centres->findAccessibleByTeacher($teacher)));
        self::assertTrue($this->centres->isAccessibleByTeacher($b, $teacher));
        self::assertFalse($this->centres->isAccessibleByTeacher($a, $teacher));

        self::assertSame([], $this->centres->findAccessibleByTeacher($stranger));
        self::assertFalse($this->centres->isAccessibleByTeacher($a, $stranger));
    }

    public function testBeingBothAdminAndYearTeacherListsTheCentreOnce(): void
    {
        $a       = $this->centre('11111111', 'Centro A');
        $teacher = $this->teacher('docente');
        $a->addAdmin($teacher);
        $year    = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($a)->addTeacher($teacher);
        $this->persist($a, $teacher, $year);

        self::assertCount(1, $this->centres->findAccessibleByTeacher($teacher));
    }

    public function testTheTenantQueryBringsTheYearAndTheRoleListsAlong(): void
    {
        $a    = $this->centre('11111111', 'Centro A');
        $boss = $this->teacher('directora');
        $a->addAdmin($boss);
        $year = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($a);
        $this->persist($a, $boss, $year);
        $a->setActiveAcademicYear($year);
        $this->flush();
        $this->em->clear();

        $loaded = $this->centres->findByIdForTenant($a->getId()->toRfc4122());

        self::assertNotNull($loaded);
        self::assertTrue($loaded->getAdmins()->isInitialized());
        self::assertTrue($loaded->getQualityManagers()->isInitialized());
        self::assertTrue($loaded->getInternalAuditors()->isInitialized());
        self::assertSame('2026-2027', $loaded->getActiveAcademicYear()?->getName());
        self::assertCount(1, $loaded->getAdmins());
    }
}
