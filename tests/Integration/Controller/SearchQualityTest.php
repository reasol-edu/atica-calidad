<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Audit;
use App\Entity\AuditProgram;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionType;
use App\Entity\Indicator;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** The "Mejora continua" groups of the ⌘K palette: fichas, acciones, indicadores and auditorías. */
final class SearchQualityTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private AcademicYear $year;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now    = new \DateTimeImmutable('2026-11-10 10:00');
        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    /** @return array<int|string, mixed> the "groups" object of the response */
    private function search(string $q): array
    {
        $this->client->request('GET', '/buscar?q=' . urlencode($q));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $groups = $data['groups'];
        self::assertIsArray($groups);

        return $groups;
    }

    /**
     * @param array<int|string, mixed> $groups
     *
     * @return list<mixed> the labels of the group's items, or [] when the group is absent
     */
    private function labels(array $groups, string $group): array
    {
        $items = $groups[$group] ?? [];
        self::assertIsArray($items);

        return array_values(array_map(static fn (mixed $i): mixed => \is_array($i) ? $i['label'] : null, $items));
    }

    public function testTheCentresQualityManagerFindsAllFourKindsOfResult(): void
    {
        $manager = $this->teacher('calidad');
        $this->centre->getQualityManagers()->add($manager);
        $finding   = (new Finding($this->centre, 'Proyector averiado en el aula 12', 'No enciende', $manager, $this->now))->setCode('NC-2026-001');
        $action    = new ImprovementAction($this->centre, $finding, ImprovementActionType::Corrective, 'Sustituir la lámpara del proyector', $manager, $this->now);
        $indicator = new Indicator($this->centre, 'Averías de proyector al trimestre', $this->now);
        $audit     = new Audit(new AuditProgram($this->centre, $this->year), 'AI-2026-01', 'Auditoría de los proyectores', $this->now, $this->now);
        $this->persist($this->centre, $this->year, $manager, $finding, $action, $indicator, $audit->getProgram(), $audit);

        $this->loginAs($manager, $this->centre);
        $groups = $this->search('proyector');

        self::assertSame(['Proyector averiado en el aula 12'], $this->labels($groups, 'findings'));
        self::assertSame(['Sustituir la lámpara del proyector'], $this->labels($groups, 'improvement_actions'));
        self::assertSame(['Averías de proyector al trimestre'], $this->labels($groups, 'indicators'));
        self::assertSame(['Auditoría de los proyectores'], $this->labels($groups, 'audits'));
    }

    public function testAPlainTeacherOnlyFindsTheirOwnFinding(): void
    {
        $reporter = $this->teacher('docente');
        $other    = $this->teacher('otro');
        $mine     = new Finding($this->centre, 'Humedad en el aula 3', 'Mancha en el techo', $reporter, $this->now);
        $theirs   = new Finding($this->centre, 'Humedad en el gimnasio', 'Mancha en la pared', $other, $this->now);
        $this->persist($this->centre, $this->year, $reporter, $other, $mine, $theirs);

        $this->loginAs($reporter, $this->centre);

        self::assertSame(['Humedad en el aula 3'], $this->labels($this->search('humedad'), 'findings'));
    }

    public function testAPlainTeacherFindsNeitherIndicatorsNorAuditsTheyHaveNothingToDoWith(): void
    {
        $teacher   = $this->teacher('docente');
        $indicator = new Indicator($this->centre, 'Satisfacción de las familias', $this->now);
        $audit     = new Audit(new AuditProgram($this->centre, $this->year), 'AI-1', 'Auditoría de familias', $this->now, $this->now);
        $this->persist($this->centre, $this->year, $teacher, $indicator, $audit->getProgram(), $audit);

        $this->loginAs($teacher, $this->centre);
        $groups = $this->search('familias');

        self::assertArrayNotHasKey('indicators', $groups);
        self::assertArrayNotHasKey('audits', $groups);
    }

    public function testThePercentSignIsLiteralInTheQuery(): void
    {
        $manager = $this->teacher('calidad');
        $this->centre->getQualityManagers()->add($manager);
        $with    = new Finding($this->centre, 'Cumplimiento del 50% de la meta', 'x', $manager, $this->now);
        $without = new Finding($this->centre, 'Cumplimiento del 5000 de la meta', 'x', $manager, $this->now);
        $this->persist($this->centre, $this->year, $manager, $with, $without);

        $this->loginAs($manager, $this->centre);

        self::assertSame(['Cumplimiento del 50% de la meta'], $this->labels($this->search('50%'), 'findings'));
    }

    public function testAFindingOfAnotherCentreNeverAppears(): void
    {
        $manager = $this->teacher('calidad');
        $this->centre->getQualityManagers()->add($manager);
        $otherCentre = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Otra');
        $foreign     = new Finding($otherCentre, 'Fuga de agua', 'x', $manager, $this->now);
        $this->persist($this->centre, $this->year, $otherCentre, $manager, $foreign);

        $this->loginAs($manager, $this->centre);

        self::assertArrayNotHasKey('findings', $this->search('fuga'));
    }
}
