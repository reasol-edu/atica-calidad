<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingEventKind;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingSeverity;
use App\Entity\FindingStatus;
use App\Entity\FindingTimelineEntry;
use App\Entity\ImprovementActionType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\FindingService;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

/**
 * The whole life cycle through FindingService and the real "finding" state machine. No user is
 * logged in here, so only the data rules of the guards apply (who may act is covered by the
 * controller tests).
 */
final class FindingServiceTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private Teacher $manager;
    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-05 10:00:00');

        $this->centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->manager = (new Teacher(new PersonName('Laura', 'Calidad')))->setUsername('calidad');
        $this->teacher = (new Teacher(new PersonName('Luis', 'Docente')))->setUsername('docente');
        $this->centre->addQualityManager($this->manager);
        $this->persist($this->centre, $this->manager, $this->teacher);
    }

    private function service(): FindingService
    {
        /** @var FindingService $service */
        $service = self::getContainer()->get(FindingService::class);

        return $service;
    }

    /** @return list<FindingEventKind> */
    private function timeline(Finding $finding): array
    {
        return array_map(static fn (FindingTimelineEntry $e): FindingEventKind => $e->getKind(), $finding->getTimeline()->toArray());
    }

    private function reportAndClassifyNonconformity(): Finding
    {
        $finding = $this->service()->report($this->centre, $this->teacher, "El proyector del aula 12 no funciona\nY el parte no se ha atendido.", null);
        $this->service()->classify($finding, $this->manager, FindingKind::Nonconformity, FindingSeverity::Minor, 'Parte de avería sin atender', null, FindingOrigin::InternalReport, $this->teacher, new \DateTimeImmutable('2026-10-20'));

        return $finding;
    }

    public function testAReportTakesItsTitleFromTheFirstLine(): void
    {
        $finding = $this->service()->report($this->centre, $this->teacher, "El proyector del aula 12 no funciona\nY el parte no se ha atendido.", null);

        self::assertSame('El proyector del aula 12 no funciona', $finding->getTitle());
        self::assertSame(FindingStatus::Reported, $finding->getStatus());
        self::assertNull($finding->getCode());
        self::assertSame([FindingEventKind::Reported], $this->timeline($finding));
    }

    public function testTheWholeLifeOfANonconformity(): void
    {
        $finding = $this->reportAndClassifyNonconformity();
        self::assertSame(FindingStatus::Analysis, $finding->getStatus());
        self::assertSame('NC-2026-001', $finding->getCode());

        // Can't finish the analysis without a root cause and a corrective action.
        self::assertSame(
            ['Falta escribir la causa raíz.', 'Añade al menos una acción correctiva.'],
            $this->service()->blockers($finding, 'submit_analysis'),
        );

        $this->service()->addAction($this->centre, $finding, $this->manager, ImprovementActionType::Repair, 'Cambiar el proyector', null, null, null, alreadyDone: true, result: 'Cambiado el 5/10');
        $this->service()->saveAnalysis($finding, ['Nadie revisa los partes', 'No hay responsable asignado'], 'No hay un responsable de revisar los partes de avería');
        $corrective = $this->service()->addAction($this->centre, $finding, $this->teacher, ImprovementActionType::Corrective, 'Asignar la revisión semanal de partes a la secretaría', $this->teacher, null, new \DateTimeImmutable('2026-10-31'));
        self::assertSame([], $this->service()->blockers($finding, 'submit_analysis'));

        $this->service()->submitAnalysis($finding, $this->teacher);
        self::assertSame(FindingStatus::Execution, $finding->getStatus());
        self::assertSame(['Falta 1 acción por hacer.'], $this->service()->blockers($finding, 'request_verification'));

        // The last action done: verification is requested on its own, due in 30 days.
        self::mockTime('2026-10-10 12:00:00');
        $this->service()->completeAction($corrective, $this->teacher, 'Asignada y comunicada');
        self::assertSame(FindingStatus::Verification, $finding->getStatus());
        self::assertSame('2026-11-09', $finding->getVerificationDueDate()?->format('Y-m-d'));

        // Not effective: back to analysis, keeping the history.
        $this->service()->verify($finding, $this->manager, false, 'Siguen llegando partes sin atender');
        self::assertSame(FindingStatus::Analysis, $finding->getStatus());
        self::assertFalse($finding->isEffective());

        // The old corrective action didn't work: a new one is needed to finish the analysis again.
        self::assertSame(
            ['Las acciones anteriores no fueron eficaces: añade al menos una acción correctiva nueva.'],
            $this->service()->blockers($finding, 'submit_analysis'),
        );
        $second = $this->service()->addAction($this->centre, $finding, $this->manager, ImprovementActionType::Corrective, 'Revisión de partes en el claustro', $this->manager, null, null);
        $this->service()->submitAnalysis($finding, $this->teacher);
        self::assertSame(FindingStatus::Execution, $finding->getStatus());
        $this->service()->completeAction($second, $this->manager, 'Incluido en el orden del día');
        self::assertSame(FindingStatus::Verification, $finding->getStatus());

        $this->service()->verify($finding, $this->manager, true, 'Un mes sin partes pendientes');
        self::assertSame(FindingStatus::Closed, $finding->getStatus());
        self::assertTrue($finding->isEffective());
        self::assertNotNull($finding->getClosedAt());

        self::assertSame([
            FindingEventKind::Reported,
            FindingEventKind::Classified,
            FindingEventKind::ActionDone,
            FindingEventKind::ActionAdded,
            FindingEventKind::AnalysisSubmitted,
            FindingEventKind::ActionDone,
            FindingEventKind::VerificationRequested,
            FindingEventKind::VerifiedIneffective,
            FindingEventKind::ActionAdded,
            FindingEventKind::AnalysisSubmitted,
            FindingEventKind::ActionDone,
            FindingEventKind::VerificationRequested,
            FindingEventKind::VerifiedEffective,
        ], $this->timeline($finding));
    }

    public function testCodesAreNumberedByKindAndYear(): void
    {
        $first  = $this->reportAndClassifyNonconformity();
        $second = $this->reportAndClassifyNonconformity();
        $other  = $this->service()->report($this->centre, $this->teacher, 'Se podría enviar el orden del día antes', null);
        $this->service()->classify($other, $this->manager, FindingKind::ImprovementOpportunity, null, 'Orden del día con antelación', null, FindingOrigin::InternalReport, null, null);

        self::mockTime('2027-01-08 09:00:00');
        $nextYear = $this->reportAndClassifyNonconformity();

        self::assertSame(['NC-2026-001', 'NC-2026-002', 'OM-2026-001', 'NC-2027-001'], [$first->getCode(), $second->getCode(), $other->getCode(), $nextYear->getCode()]);
    }

    public function testAnObservationClosesWithoutVerification(): void
    {
        $finding = $this->service()->report($this->centre, $this->teacher, 'Las actas se suben tarde', null);
        $this->service()->classify($finding, $this->manager, FindingKind::Observation, FindingSeverity::Major, 'Actas tardías', null, FindingOrigin::InternalReport, $this->teacher, new \DateTimeImmutable('2026-10-20'));

        self::assertSame(FindingStatus::Execution, $finding->getStatus());
        self::assertNull($finding->getSeverity(), 'only a nonconformity has a severity');
        self::assertNull($finding->getAnalysisResponsible());
        self::assertSame(['Solo las no conformidades pasan por la verificación de eficacia.'], $this->service()->blockers($finding, 'request_verification'));

        $action = $this->service()->addAction($this->centre, $finding, $this->manager, ImprovementActionType::Preventive, 'Recordatorio en el claustro', null, null, null);
        self::assertSame($this->manager, $action->getResponsibleTeacher(), 'without a responsible, whoever adds it');
        self::assertSame(['Falta 1 acción por hacer.'], $this->service()->blockers($finding, 'close'));

        $this->service()->completeAction($action, $this->manager, null);
        self::assertSame(FindingStatus::Execution, $finding->getStatus(), 'not closed on its own');
        $this->service()->close($finding, $this->manager, 'Recordado');
        self::assertSame(FindingStatus::Closed, $finding->getStatus());
    }

    public function testDiscarding(): void
    {
        $finding = $this->service()->report($this->centre, $this->teacher, 'La cafetería cierra pronto', null);
        $this->service()->discard($finding, $this->manager, 'No depende del sistema de calidad');

        self::assertSame(FindingStatus::Discarded, $finding->getStatus());
        self::assertSame('No depende del sistema de calidad', $finding->getDiscardReason());
        self::assertNull($finding->getCode());

        $this->expectException(NotEnabledTransitionException::class);
        $this->service()->close($finding, $this->manager, '');
    }

    public function testAFindingKeepsItsSection(): void
    {
        $section = (new DocumentSection())->setEducationalCentre($this->centre)->setName('8.5 Prestación del servicio');
        $this->persist($section);

        $finding = $this->service()->report($this->centre, $this->teacher, 'Algo', $section);
        self::assertSame($section, $finding->getSection());
    }
}
