<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** Utilidades › Calculadora de fechas: stateless, no ownership — just a request in, a result out. */
final class DateCalculatorControllerTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->teacher = (new Teacher(new PersonName('Nombre', 'docente')))->setUsername('docente');
        $this->persist($this->centre, $this->year, $this->teacher);
        $this->loginAs($this->teacher, $this->centre);
    }

    private function csrfToken(): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken('utilities_date_calculator')->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    /** @param array<string, string> $overrides */
    private function baseValues(array $overrides = []): array
    {
        return array_replace([
            'academicYear'   => $this->year->getId()->toRfc4122(),
            'mode'           => 'date_range',
            'startDate'      => '',
            'endDate'        => '',
            'totalHours'     => '',
            'mondayHours'    => '6',
            'tuesdayHours'   => '6',
            'wednesdayHours' => '6',
            'thursdayHours'  => '6',
            'fridayHours'    => '6',
        ], $overrides);
    }

    public function testTheFormPageLoads(): void
    {
        $this->client->request('GET', '/utilidades/calculadora-fechas');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('2026-2027', (string) $this->client->getResponse()->getContent());
    }

    public function testWorkingDaysBetweenTwoDatesReturnsTheExpectedTotals(): void
    {
        $this->client->request('GET', '/utilidades/calculadora-fechas');
        $this->client->request('POST', '/utilidades/calculadora-fechas', $this->baseValues([
            '_token'    => $this->csrfToken(),
            'startDate' => '2026-03-02',
            'endDate'   => '2026-03-06',
        ]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // Mon-Fri 2026-03-02..06, 6h/day: 5 working days, 30 total hours.
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('30 h', $content);
    }

    public function testExportingTheResultDownloadsAnExcelFile(): void
    {
        $this->client->request('GET', '/utilidades/calculadora-fechas');
        $this->client->request('POST', '/utilidades/calculadora-fechas', $this->baseValues([
            '_token'    => $this->csrfToken(),
            'action'    => 'export_excel',
            'startDate' => '2026-03-02',
            'endDate'   => '2026-03-06',
        ]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testExportingAnInvalidFormShowsTheFormErrorInsteadOfAFile(): void
    {
        $this->client->request('GET', '/utilidades/calculadora-fechas');
        $this->client->request('POST', '/utilidades/calculadora-fechas', $this->baseValues([
            '_token'         => $this->csrfToken(),
            'action'         => 'export_excel',
            'startDate'      => '2026-03-02',
            'endDate'        => '2026-03-06',
            'mondayHours'    => '',
            'tuesdayHours'   => '',
            'wednesdayHours' => '',
            'thursdayHours'  => '',
            'fridayHours'    => '',
        ]));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testEndDateForHoursReturnsTheComputedEndDate(): void
    {
        $this->client->request('GET', '/utilidades/calculadora-fechas');
        $this->client->request('POST', '/utilidades/calculadora-fechas', $this->baseValues([
            '_token'     => $this->csrfToken(),
            'mode'       => 'start_with_hours',
            'startDate'  => '2026-03-02',
            'totalHours' => '20',
            'mondayHours'    => '8',
            'tuesdayHours'   => '8',
            'wednesdayHours' => '8',
            'thursdayHours'  => '8',
            'fridayHours'    => '8',
        ]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('04/03/2026', (string) $this->client->getResponse()->getContent());
    }

    public function testStartDateForHoursReturnsTheComputedStartDate(): void
    {
        $this->client->request('GET', '/utilidades/calculadora-fechas');
        $this->client->request('POST', '/utilidades/calculadora-fechas', $this->baseValues([
            '_token'     => $this->csrfToken(),
            'mode'       => 'end_with_hours',
            'endDate'    => '2026-03-06',
            'totalHours' => '20',
            'mondayHours'    => '8',
            'tuesdayHours'   => '8',
            'wednesdayHours' => '8',
            'thursdayHours'  => '8',
            'fridayHours'    => '8',
        ]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('04/03/2026', (string) $this->client->getResponse()->getContent());
    }

    public function testMissingWeekdayHoursIsRejected(): void
    {
        $this->client->request('GET', '/utilidades/calculadora-fechas');
        $this->client->request('POST', '/utilidades/calculadora-fechas', $this->baseValues([
            '_token'         => $this->csrfToken(),
            'startDate'      => '2026-03-02',
            'endDate'        => '2026-03-06',
            'mondayHours'    => '',
            'tuesdayHours'   => '',
            'wednesdayHours' => '',
            'thursdayHours'  => '',
            'fridayHours'    => '',
        ]));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testAnInvalidAcademicYearIsRejected(): void
    {
        $this->client->request('GET', '/utilidades/calculadora-fechas');
        $this->client->request('POST', '/utilidades/calculadora-fechas', $this->baseValues([
            '_token'       => $this->csrfToken(),
            'academicYear' => 'not-a-uuid',
            'startDate'    => '2026-03-02',
            'endDate'      => '2026-03-06',
        ]));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }
}
