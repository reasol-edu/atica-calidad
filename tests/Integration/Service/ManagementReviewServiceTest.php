<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingStatus;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionType;
use App\Entity\ManagementReview;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\FindingService;
use App\Service\ManagementReviewBuilder;
use App\Service\ManagementReviewService;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The management review through ManagementReviewService and ManagementReviewBuilder: what it
 * compiles for its period, how its decisions and the previous review's are tracked, and that
 * closing it freezes what it showed.
 */
final class ManagementReviewServiceTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $manager;
    private Teacher $director;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-05 10:00:00');

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->manager  = (new Teacher(new PersonName('Laura', 'Calidad')))->setUsername('calidad');
        $this->director = (new Teacher(new PersonName('Javier', 'Director')))->setUsername('director');
        $this->centre->addQualityManager($this->manager);
        $this->centre->addAdmin($this->director);

        $this->persist($this->centre, $this->year, $this->manager, $this->director);
    }

    private function service(): ManagementReviewService
    {
        /** @var ManagementReviewService $service */
        $service = self::getContainer()->get(ManagementReviewService::class);

        return $service;
    }

    private function builder(): ManagementReviewBuilder
    {
        /** @var ManagementReviewBuilder $builder */
        $builder = self::getContainer()->get(ManagementReviewBuilder::class);

        return $builder;
    }

    private function review(string $heldOn, string $from, string $to): ManagementReview
    {
        return $this->service()->create($this->centre, $this->year, $this->manager, 'Revisión ' . $heldOn, new \DateTimeImmutable($heldOn), new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    private function finding(string $title, string $reportedAt, FindingOrigin $origin, ?FindingKind $kind, FindingStatus $status): Finding
    {
        $finding = (new Finding($this->centre, $title, $title, $this->manager, new \DateTimeImmutable($reportedAt)))->setOrigin($origin)->setKind($kind);
        $finding->setStatus($status);
        $this->persist($finding);

        return $finding;
    }

    private function decision(ManagementReview $review, string $description): ImprovementAction
    {
        /** @var FindingService $findings */
        $findings = self::getContainer()->get(FindingService::class);

        return $findings->createPlanAction($this->centre, $this->year, $this->manager, ImprovementActionType::Improvement, $description, null, null, $this->manager, null, new \DateTimeImmutable('2026-10-30'), null, $review);
    }

    public function testItCompilesTheFindingsOfItsPeriod(): void
    {
        $this->finding('Queja por el ruido', '2026-09-20 09:00', FindingOrigin::Complaint, FindingKind::Nonconformity, FindingStatus::Analysis);
        $this->finding('Mejorar la web', '2026-09-25 09:00', FindingOrigin::InternalReport, FindingKind::ImprovementOpportunity, FindingStatus::Execution);
        $this->finding('Por clasificar', '2026-10-01 09:00', FindingOrigin::InternalReport, null, FindingStatus::Reported);
        // Before the period: only counts while still open (as an open nonconformity).
        $this->finding('NC antigua abierta', '2026-06-01 09:00', FindingOrigin::InternalAudit, FindingKind::Nonconformity, FindingStatus::Verification);
        $this->finding('NC antigua cerrada', '2026-06-01 09:00', FindingOrigin::InternalAudit, FindingKind::Nonconformity, FindingStatus::Closed);
        $effective = $this->finding('NC verificada', '2026-06-10 09:00', FindingOrigin::InternalReport, FindingKind::Nonconformity, FindingStatus::Verification);
        $effective->recordVerification(true, 'Funcionó', $this->manager, new \DateTimeImmutable('2026-09-15 10:00'));
        $effective->setStatus(FindingStatus::Closed);
        $this->flush();

        $findings = $this->builder()->build($this->review('2026-10-05', '2026-09-01', '2026-10-05'))['findings'];

        self::assertSame(3, $findings['reported']);
        self::assertSame(['nonconformity' => 1, 'observation' => 0, 'improvement_opportunity' => 1, 'unclassified' => 1, 'discarded' => 0], $findings['byKind']);
        self::assertSame(['internal_report' => 2, 'complaint' => 1], $findings['byOrigin']);
        self::assertSame(['Queja por el ruido'], array_column($findings['complaints'], 'title'));
        self::assertSame(['Mejorar la web'], array_column($findings['opportunities'], 'title'));
        self::assertSame(['NC antigua abierta', 'Queja por el ruido'], array_column($findings['openNonconformities'], 'title'));
        self::assertSame(1, $findings['effective']);
        self::assertSame(0, $findings['ineffective']);
    }

    public function testThePreviousReviewsDecisionsAndTheDefaultPeriod(): void
    {
        [$from, $to] = $this->builder()->defaultPeriod($this->centre);
        self::assertSame(['2026-09-01', '2026-10-05'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);

        $first = $this->review('2026-09-10', '2026-09-01', '2026-09-10');
        $done  = $this->decision($first, 'Encuesta a las familias');
        $this->decision($first, 'Guía de acogida');
        /** @var FindingService $findings */
        $findings = self::getContainer()->get(FindingService::class);
        $findings->completeAction($done, $this->manager, 'Hecha');

        [$from] = $this->builder()->defaultPeriod($this->centre);
        self::assertSame('2026-09-11', $from->format('Y-m-d'));

        $second   = $this->review('2026-10-05', '2026-09-11', '2026-10-05');
        $previous = $this->builder()->build($second)['previousActions'];
        self::assertSame('Revisión 2026-09-10', $previous['review']);
        self::assertSame(['Encuesta a las familias' => 'done', 'Guía de acogida' => 'pending'], array_column($previous['items'], 'status', 'description'));
        self::assertSame('Laura Calidad', $previous['items'][0]['responsible']);

        // The plan counts them too.
        self::assertSame(2, $this->builder()->build($second)['plan']['total']);
    }

    public function testClosingFreezesItsInputs(): void
    {
        $review = $this->review('2026-10-05', '2026-09-01', '2026-10-05');
        self::assertSame(['review.blocker.conclusions'], $this->service()->blockers($review));

        $this->service()->save($review, 'Revisión de octubre', $review->getHeldOn(), $review->getPeriodStart(), $review->getPeriodEnd(), 'Dirección', null, null, null, null, 'El sistema es eficaz.');
        self::assertSame([], $this->service()->blockers($review));
        $this->service()->close($review, $this->director);
        self::assertTrue($review->isClosed());
        self::assertSame(0, $this->builder()->inputsOf($review)['findings']['reported']);

        // What happens afterwards doesn't change it.
        $this->finding('Queja tardía', '2026-10-01 09:00', FindingOrigin::Complaint, FindingKind::Nonconformity, FindingStatus::Analysis);
        $this->flush();
        $this->em->clear();
        $review = $this->em->find(ManagementReview::class, $review->getId());
        self::assertNotNull($review);
        self::assertSame(0, $this->builder()->inputsOf($review)['findings']['reported']);
        self::assertSame(1, $this->builder()->build($review)['findings']['reported']);

        $this->expectException(\LogicException::class);
        $this->service()->delete($review);
    }

    public function testAReviewNotHeldYetCantBeClosed(): void
    {
        $review = $this->review('2026-10-20', '2026-09-01', '2026-10-20');
        $this->service()->save($review, $review->getTitle(), $review->getHeldOn(), $review->getPeriodStart(), $review->getPeriodEnd(), null, null, null, null, null, 'Conclusiones');

        self::assertSame(['review.blocker.not_held'], $this->service()->blockers($review));
    }

    public function testDeletingItKeepsItsDecisionsInThePlan(): void
    {
        $review   = $this->review('2026-10-05', '2026-09-01', '2026-10-05');
        $decision = $this->decision($review, 'Guía de acogida');
        $id       = $decision->getId();

        $this->service()->delete($review);
        $this->em->clear();

        $decision = $this->em->find(ImprovementAction::class, $id);
        self::assertNotNull($decision);
        self::assertNull($decision->getManagementReview());
        self::assertSame([], $this->em->getRepository(ManagementReview::class)->findAll());
    }
}
