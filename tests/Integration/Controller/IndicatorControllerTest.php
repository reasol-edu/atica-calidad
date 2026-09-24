<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\ImprovementAction;
use App\Entity\Indicator;
use App\Entity\Measurement;
use App\Entity\MeasurementCalendar;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** Indicators from the screens: the manager defines them, the responsible records, the manager decides on what's off target. */
final class IndicatorControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $manager;
    private Teacher $responsible;
    private Teacher $auditor;
    private Teacher $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-28 10:00:00');

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->manager     = $this->teacher('calidad', 'Laura');
        $this->responsible = $this->teacher('jefa', 'Ana');
        $this->auditor     = $this->teacher('auditora', 'Irene');
        $this->stranger    = $this->teacher('otro', 'Olga');
        $this->centre->addQualityManager($this->manager);
        $this->centre->addInternalAuditor($this->auditor);
        $this->persist($this->centre, $this->year, $this->manager, $this->responsible, $this->auditor, $this->stranger);
    }

    private function teacher(string $username, string $first): Teacher
    {
        $teacher = (new Teacher(new PersonName($first, ucfirst($username))))->setUsername($username);
        $teacher->addAcademicYear($this->year);

        return $teacher;
    }

    /** See FolderControllerTest for why this push/save dance is needed between KernelBrowser requests. */
    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function idFrom(string $pattern): string
    {
        preg_match($pattern, (string) $this->client->getResponse()->headers->get('Location'), $m);
        self::assertNotEmpty($m[1] ?? null, (string) $this->client->getResponse()->headers->get('Location'));

        return $m[1];
    }

    /** The manager creates "Evaluaciones" from its template and an indicator on it for Ana; returns [indicator id, 1st period id]. */
    private function setUpIndicator(): array
    {
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora/indicadores/calendarios');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/mejora/indicadores/calendarios', [
            '_token'   => $this->csrfToken('quality_calendar_new'),
            'name'     => 'Evaluaciones',
            'template' => 'evaluations',
        ]);
        $calendarId = $this->idFrom('#/calendarios/([0-9a-f-]{36})#');

        $this->client->request('GET', '/mejora/indicadores/nuevo');
        $this->client->request('POST', '/mejora/indicadores/nuevo', [
            '_token'         => $this->csrfToken('quality_indicator'),
            'name'           => 'Alumnado que promociona',
            'description'    => 'Promocionan / matriculados × 100',
            'section'        => '',
            'unit'           => '%',
            'direction'      => 'higher',
            'responsible'    => 't:' . $this->responsible->getId()->toRfc4122(),
            'calendar'       => $calendarId,
            'target'         => '85',
            'alertThreshold' => '80,5',
        ]);
        $indicatorId = $this->idFrom('#/mejora/indicadores/([0-9a-f-]{36})#');

        $this->em->clear();
        $calendar = $this->em->find(MeasurementCalendar::class, $calendarId);
        self::assertNotNull($calendar);

        return [$indicatorId, ($calendar->getPeriods()->first() ?: throw new \LogicException())->getId()->toRfc4122()];
    }

    public function testTheWholeCycleFromTheScreens(): void
    {
        [$indicatorId, $periodId] = $this->setUpIndicator();

        $this->em->clear();
        $indicator = $this->em->find(Indicator::class, $indicatorId);
        $target    = $indicator?->targetFor($this->em->find(AcademicYear::class, $this->year->getId()) ?? throw new \LogicException());
        self::assertSame([85.0, 80.5], [$target?->getTarget(), $target?->getAlertThreshold()]);

        // Ana has it to record, and records it with a comma.
        $this->loginAs($this->responsible, $this->centre);
        $this->client->request('GET', '/');
        self::assertStringContainsString('Registrar: Alumnado que promociona', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/indicadores/' . $indicatorId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Por registrar', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', '/mejora/indicadores/' . $indicatorId . '/periodos/' . $periodId, [
            '_token' => $this->csrfToken('quality_measurement_' . $indicatorId),
            'value'  => '78,5',
            'notes'  => 'Datos de Séneca',
        ]);
        $this->client->followRedirect();
        self::assertStringContainsString('fuera de meta', (string) $this->client->getResponse()->getContent());
        // She can't decide what's done about it: that's the manager's.
        self::assertStringNotContainsString('Abrir no conformidad', (string) $this->client->getResponse()->getContent());

        // The manager proposes an improvement action from it.
        $this->em->clear();
        $measurement = $this->em->getRepository(Measurement::class)->findOneBy([]);
        self::assertInstanceOf(Measurement::class, $measurement);
        self::assertSame(78.5, $measurement->getValue());
        $mid = $measurement->getId()->toRfc4122();

        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora');
        self::assertStringContainsString('Fuera de meta: Alumnado que promociona', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/indicadores/' . $indicatorId);
        self::assertStringContainsString('Abrir no conformidad', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/plan/nueva?medicion=' . $mid);
        self::assertStringContainsString('Que «Alumnado que promociona» vuelva a la meta (85 %).', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', '/mejora/plan/nueva', [
            '_token'      => $this->csrfToken('quality_plan_action'),
            'measurement' => $mid,
            'type'        => 'improvement',
            'description' => 'Refuerzo en 1.º de ESO',
            'goal'        => 'Volver a la meta',
            'section'     => '',
            'responsible' => '',
            'dueDate'     => '2027-02-15',
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        $action = $this->em->getRepository(ImprovementAction::class)->findOneBy(['description' => 'Refuerzo en 1.º de ESO']);
        self::assertSame($mid, $action?->getMeasurement()?->getId()->toRfc4122());
        self::assertTrue($this->em->find(Measurement::class, $mid)?->isReviewed());

        // The indicator shows what came of it.
        $this->client->request('GET', '/mejora/indicadores/' . $indicatorId);
        self::assertStringContainsString('Se abrió:', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString((string) $action?->getCode(), (string) $this->client->getResponse()->getContent());

        // And the board and both reports have it.
        $this->client->request('GET', '/mejora/indicadores');
        self::assertStringContainsString('78,5 %', (string) $this->client->getResponse()->getContent());
        foreach (['pdf' => 'application/pdf', 'xlsx' => 'spreadsheetml'] as $format => $type) {
            $this->client->request('GET', '/centro/' . $this->centre->getId()->toRfc4122() . '/informes/indicadores.' . $format);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString($type, (string) $this->client->getResponse()->headers->get('Content-Type'));
        }
    }

    public function testOpeningAFindingFromAValue(): void
    {
        [$indicatorId, $periodId] = $this->setUpIndicator();
        $this->client->request('GET', '/mejora/indicadores/' . $indicatorId);
        $this->client->request('POST', '/mejora/indicadores/' . $indicatorId . '/periodos/' . $periodId, [
            '_token' => $this->csrfToken('quality_measurement_' . $indicatorId),
            'value'  => '60',
        ]);
        $this->em->clear();
        $mid = ($this->em->getRepository(Measurement::class)->findOneBy([]) ?? throw new \LogicException())->getId()->toRfc4122();

        $this->client->request('GET', '/mejora/indicadores/' . $indicatorId);
        $this->client->request('POST', '/mejora/indicadores/mediciones/' . $mid . '/no-conformidad', ['_token' => $this->csrfToken('quality_review_' . $mid)]);
        $findingId = $this->idFrom('#/mejora/fichas/([0-9a-f-]{36})#');
        $this->em->clear();
        self::assertSame($mid, $this->em->find(Finding::class, $findingId)?->getMeasurement()?->getId()->toRfc4122());
    }

    public function testTheFormsRefuseWhatTheyMust(): void
    {
        [$indicatorId, $periodId] = $this->setUpIndicator();

        $this->client->request('GET', '/mejora/indicadores/nuevo');
        $this->client->request('POST', '/mejora/indicadores/nuevo', [
            '_token'         => $this->csrfToken('quality_indicator'),
            'name'           => '',
            'direction'      => 'higher',
            'responsible'    => 'x',
            'calendar'       => '',
            'target'         => '85',
            'alertThreshold' => '90', // above the target, with more being better
        ]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Pon un nombre.', $content);
        self::assertStringContainsString('el umbral de alerta tiene que ser menor que la meta', $content);

        $this->client->request('POST', '/mejora/indicadores/' . $indicatorId . '/periodos/' . $periodId, [
            '_token' => $this->csrfToken('quality_measurement_' . $indicatorId),
            'value'  => 'mucho',
        ]);
        $this->client->followRedirect();
        self::assertStringContainsString('Escribe el valor como un número.', (string) $this->client->getResponse()->getContent());

        // A calendar some indicator uses can't be deleted.
        $this->em->clear();
        $calendarId = ($this->em->getRepository(MeasurementCalendar::class)->findOneBy([]) ?? throw new \LogicException())->getId()->toRfc4122();
        $this->client->request('GET', '/mejora/indicadores/calendarios/' . $calendarId);
        $this->client->request('POST', '/mejora/indicadores/calendarios/' . $calendarId . '/eliminar', ['_token' => $this->csrfToken('quality_calendar_' . $calendarId)]);
        $this->em->clear();
        self::assertNotNull($this->em->find(MeasurementCalendar::class, $calendarId));
    }

    public function testWhoCanSeeAndDoWhat(): void
    {
        [$indicatorId, $periodId] = $this->setUpIndicator();

        // The auditor sees the board and the indicator, but records and defines nothing.
        $this->loginAs($this->auditor, $this->centre);
        $this->client->request('GET', '/mejora/indicadores');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Nuevo indicador', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/indicadores/' . $indicatorId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('name="value"', (string) $this->client->getResponse()->getContent());
        foreach (['/mejora/indicadores/nuevo', '/mejora/indicadores/calendarios', '/mejora/indicadores/' . $indicatorId . '/editar'] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }

        // The responsible sees her indicator, not the board.
        $this->loginAs($this->responsible, $this->centre);
        $this->client->request('GET', '/mejora/indicadores');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/mejora/indicadores/' . $indicatorId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Anyone else: nothing.
        $this->loginAs($this->stranger, $this->centre);
        $this->client->request('GET', '/mejora/indicadores/' . $indicatorId);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/mejora/indicadores/' . $indicatorId . '/periodos/' . $periodId, ['_token' => 'x', 'value' => '1']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
