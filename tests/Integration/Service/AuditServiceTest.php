<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Audit;
use App\Entity\AuditResult;
use App\Entity\AuditStatus;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingSeverity;
use App\Entity\FindingStatus;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\QualityTask;
use App\Service\AuditChecklistLibrary;
use App\Service\AuditService;
use App\Service\FindingService;
use App\Service\QualityTaskFinder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Internal audits through AuditService and the real "audit" state machine, from the programme to
 * closing. Nobody is logged in, so only the data rules of the guards apply.
 */
final class AuditServiceTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $manager;
    private Teacher $director;
    private Teacher $auditor;
    private Teacher $headOfDepartment;
    private DocumentSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-05 10:00:00');

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->manager          = (new Teacher(new PersonName('Laura', 'Calidad')))->setUsername('calidad');
        $this->director         = (new Teacher(new PersonName('Javier', 'Director')))->setUsername('director');
        $this->auditor          = (new Teacher(new PersonName('Irene', 'Auditora')))->setUsername('auditora');
        $this->headOfDepartment = (new Teacher(new PersonName('Paula', 'Jefa')))->setUsername('jefa');
        $this->centre->addQualityManager($this->manager);
        $this->centre->addAdmin($this->director);
        $this->centre->addInternalAuditor($this->auditor);

        // "8.1 Planificación", with a folder whose responsible is the head of department.
        $this->section = (new DocumentSection())->setName('8.1 Planificación')->setEducationalCentre($this->centre)->setPosition(0);
        $folder        = (new Folder())->setName('Programaciones')->setDocumentSection($this->section)->setPosition(0);
        $this->section->getFolders()->add($folder);
        $profile = (new SpecificProfile())->setName('Jefatura de departamento')->setEducationalCentre($this->centre)->setPosition(0);
        $profile->addAssignment($this->headOfDepartment);
        $folder->addResponsibleProfile($profile);

        $this->persist($this->centre, $this->year, $this->manager, $this->director, $this->auditor, $this->headOfDepartment, $this->section, $folder, $profile);
    }

    private function service(): AuditService
    {
        /** @var AuditService $service */
        $service = self::getContainer()->get(AuditService::class);

        return $service;
    }

    /** @param list<Teacher> $auditors */
    private function plan(string $title, string $month = '2026-11-01', array $auditors = []): Audit
    {
        return $this->service()->saveAudit($this->centre, $this->year, null, [
            'title' => $title, 'objective' => null, 'scope' => [$this->section],
            'plannedMonth' => new \DateTimeImmutable($month), 'lead' => $this->auditor, 'auditors' => $auditors,
        ]);
    }

    /** Prepared with three points and started. */
    private function started(): Audit
    {
        $audit = $this->plan('Planificación');
        $this->service()->savePreparation($audit, $this->auditor, new \DateTimeImmutable('2026-11-10 09:30'), null, [
            ['id' => null, 'clause' => '8.1', 'question' => '¿Se planifica el curso?', 'guidance' => null],
            ['id' => null, 'clause' => '8.1', 'question' => '¿Se entregan las programaciones en plazo?', 'guidance' => null],
            ['id' => null, 'clause' => '8.1', 'question' => '¿Se controlan los cambios?', 'guidance' => null],
        ]);
        $this->service()->start($audit, $this->auditor);

        return $audit;
    }

    public function testTheProgrammeNumbersItsAuditsAndAddingOneAsksForApprovalAgain(): void
    {
        $first = $this->plan('Política');
        $this->service()->approve($first->getProgram(), $this->director);
        self::assertTrue($first->getProgram()->isApproved());

        $second = $this->plan('Planificación');
        self::assertSame(['AI-2026-01', 'AI-2026-02'], [$first->getCode(), $second->getCode()]);
        self::assertFalse($second->getProgram()->isApproved());
    }

    public function testWhoIsAuditedAndWhoWouldAuditTheirOwnProcess(): void
    {
        $audit = $this->plan('Planificación', auditors: [$this->headOfDepartment]);

        self::assertSame(['jefa'], array_map(static fn (Teacher $t): ?string => $t->getUsername(), $this->service()->auditees($audit)));
        $conflicts = $this->service()->independenceConflicts($audit->getScope(), $audit->getTeam());
        self::assertCount(1, $conflicts);
        self::assertSame('jefa', $conflicts[0]['teacher']->getUsername());
        self::assertSame(['8.1 Planificación'], array_map(static fn (DocumentSection $s): string => $s->getName(), $conflicts[0]['sections']));
    }

    public function testItNeedsADateAndAChecklistToStart(): void
    {
        $audit = $this->plan('Planificación');
        $this->service()->savePreparation($audit, $this->auditor, null, 'Objetivo', []);
        self::assertSame(AuditStatus::Preparation, $audit->getStatus());
        self::assertSame(['Falta la fecha de la auditoría.', 'Falta la lista de comprobación.'], $this->service()->blockers($audit, 'start'));

        /** @var AuditChecklistLibrary $library */
        $library = self::getContainer()->get(AuditChecklistLibrary::class);
        self::assertGreaterThan(15, $library->loadIso($this->centre));
        self::assertSame(0, $library->loadIso($this->centre), 'loading them again adds nothing');
        $template = self::getContainer()->get(\App\Repository\AuditChecklistTemplateRepository::class)->findByCentre($this->centre)[0];
        $this->service()->addChecklist($audit, $this->auditor, $template);
        $audit->setScheduledAt(new \DateTimeImmutable('2026-11-10 09:30'));
        self::assertSame([], $this->service()->blockers($audit, 'start'));
    }

    public function testIssuingTheReportRaisesClassifiedFindingsAndClosesWhenTheyAreClosed(): void
    {
        $audit = $this->started();
        [$plan, $programmes, $changes] = array_values($audit->getItems()->toArray());

        self::assertSame(['Faltan 3 puntos por revisar.', 'Falta la conclusión.'], $this->service()->blockers($audit, 'issue_report'));

        $this->service()->record($plan, AuditResult::Conforming, null, null);
        $this->service()->record($programmes, AuditResult::Nonconformity, FindingSeverity::Major, 'Cuatro programaciones de 12, fuera de plazo.');
        $this->service()->record($changes, AuditResult::Observation, FindingSeverity::Major, 'Los cambios de horario no quedan registrados.');
        self::assertNull($changes->getSeverity(), 'only a nonconformity has a severity');
        $this->service()->saveReport($audit, 'Buena planificación del curso.', 'Proceso adecuado, con un problema de plazos.');
        self::assertSame([], $this->service()->blockers($audit, 'issue_report'));

        $raised = $this->service()->issueReport($audit, $this->auditor);
        self::assertSame(AuditStatus::ReportIssued, $audit->getStatus());
        self::assertSame(['NC-2026-001', 'OB-2026-001'], array_map(static fn ($f): ?string => $f->getCode(), $raised));
        [$nc, $ob] = $raised;
        self::assertSame([FindingKind::Nonconformity, FindingSeverity::Major, FindingStatus::Analysis, FindingOrigin::InternalAudit], [$nc->getKind(), $nc->getSeverity(), $nc->getStatus(), $nc->getOrigin()]);
        self::assertSame('Cuatro programaciones de 12, fuera de plazo.', $nc->getTitle());
        self::assertSame('2026-10-20', $nc->getAnalysisDueDate()?->format('Y-m-d'));
        self::assertSame([FindingKind::Observation, FindingStatus::Execution], [$ob->getKind(), $ob->getStatus()]);
        self::assertSame($programmes, $nc->getAuditItem());

        // Closing the observation isn't enough; closing the nonconformity too closes the audit.
        /** @var FindingService $findings */
        $findings = self::getContainer()->get(FindingService::class);
        $findings->close($ob, $this->manager, 'Registro de cambios creado.');
        self::assertSame(AuditStatus::ReportIssued, $audit->getStatus());
        $findings->saveAnalysis($nc, [], 'Nadie recordaba el plazo.');
        $findings->addAction($this->centre, $nc, $this->manager, \App\Entity\ImprovementActionType::Corrective, 'Recordatorio automático', null, null, null, alreadyDone: true, result: 'Activado');
        $findings->submitAnalysis($nc, $this->manager);
        $findings->verify($nc, $this->manager, true, 'Este curso, todas en plazo.');
        self::assertSame(AuditStatus::Closed, $audit->getStatus());
        self::assertNotNull($audit->getClosedAt());
    }

    public function testAReportWithNothingToDealWithClosesAtOnce(): void
    {
        $audit = $this->started();
        foreach ($audit->getItems() as $item) {
            $this->service()->record($item, AuditResult::Conforming, null, null);
        }
        $this->service()->saveReport($audit, null, 'Todo conforme.');

        self::assertSame([], $this->service()->issueReport($audit, $this->auditor));
        self::assertSame(AuditStatus::Closed, $audit->getStatus());
    }

    public function testTheTasksItGives(): void
    {
        $this->plan('Política', '2026-10-01');
        $this->plan('Evaluación', '2027-02-01');

        /** @var QualityTaskFinder $finder */
        $finder = self::getContainer()->get(QualityTaskFinder::class);
        $describe = static fn (array $tasks): array => array_map(static fn (QualityTask $t): string => $t->type . ':' . $t->label(), $tasks);

        // The management team approves the programme; the lead auditor has the October audit (not yet February's).
        self::assertSame(['approve:2026-2027'], $describe($finder->forTeacher($this->director, $this->centre)));
        self::assertSame(['audit:Política'], $describe($finder->forTeacher($this->auditor, $this->centre)));

        $audit = $this->started();
        // Scheduled on Nov 10: the head of department, audited, has it in the calendar.
        self::assertSame(['audit:Planificación'], $describe($finder->dueBetween($this->headOfDepartment, $this->centre, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'))));
        self::assertSame(AuditStatus::InProgress, $audit->getStatus());
    }

    public function testCopyingLastYearsProgramme(): void
    {
        $previous = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($this->centre);
        $this->persist($previous);
        $this->service()->saveAudit($this->centre, $previous, null, [
            'title' => 'Planificación', 'objective' => 'Ver plazos', 'scope' => [$this->section],
            'plannedMonth' => new \DateTimeImmutable('2025-11-01'), 'lead' => $this->auditor, 'auditors' => [],
        ]);

        self::assertSame(1, $this->service()->copyProgram($this->centre, $previous, $this->year));
        self::assertSame(0, $this->service()->copyProgram($this->centre, $previous, $this->year), 'only into an empty programme');
        $copy = $this->service()->program($this->centre, $this->year)->getAudits()->first();
        self::assertNotFalse($copy);
        self::assertSame(['AI-2026-01', '2026-11', AuditStatus::Planned], [$copy->getCode(), $copy->getPlannedMonth()->format('Y-m'), $copy->getStatus()]);
    }
}
