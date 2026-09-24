<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Audit;
use App\Entity\AuditChecklistTemplate;
use App\Entity\AuditStatus;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** Internal audits from the screens: the manager plans, the direction approves, the auditor prepares and carries it out. */
final class AuditControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $manager;
    private Teacher $director;
    private Teacher $auditor;
    private Teacher $auditee;
    private Teacher $stranger;
    private DocumentSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-05 10:00:00');

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->manager  = $this->teacher('calidad', 'Laura');
        $this->director = $this->teacher('director', 'Javier');
        $this->auditor  = $this->teacher('auditora', 'Irene');
        $this->auditee  = $this->teacher('jefa', 'Paula');
        $this->stranger = $this->teacher('otro', 'Olga');
        $this->centre->addQualityManager($this->manager);
        $this->centre->addAdmin($this->director);
        $this->centre->addInternalAuditor($this->auditor);

        $this->section = (new DocumentSection())->setName('8.1 Planificación')->setEducationalCentre($this->centre)->setPosition(0);
        $folder        = (new Folder())->setName('Programaciones')->setDocumentSection($this->section)->setPosition(0);
        $profile       = (new SpecificProfile())->setName('Jefatura')->setEducationalCentre($this->centre)->setPosition(0);
        $profile->addAssignment($this->auditee);
        $folder->addResponsibleProfile($profile);
        $template = (new AuditChecklistTemplate($this->centre, '8.1 Planificación', '8.1'))->setItems([
            ['clause' => '8.1', 'question' => '¿Se planifica el curso?', 'guidance' => null],
            ['clause' => '8.1', 'question' => '¿Se entregan las programaciones en plazo?', 'guidance' => 'Ver la actividad'],
        ]);

        $this->persist($this->centre, $this->year, $this->manager, $this->director, $this->auditor, $this->auditee, $this->stranger, $this->section, $folder, $profile, $template);
    }

    private function teacher(string $username, string $first): Teacher
    {
        $teacher = (new Teacher(new PersonName($first, ucfirst($username))))->setUsername($username)->setEmail($username . '@example.com');
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

    private function audit(string $id): Audit
    {
        $this->em->clear();
        $audit = $this->em->find(Audit::class, $id);
        self::assertNotNull($audit);

        return $audit;
    }

    /** The manager plans the audit (with the auditee on the team, to see the warning); returns its id. */
    private function plan(bool $withAuditeeOnTeam = false): string
    {
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora/auditorias/nueva');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/mejora/auditorias/nueva', [
            '_token'       => $this->csrfToken('quality_audit_form'),
            'title'        => 'Planificación y control operacional',
            'objective'    => 'Ver plazos',
            'scope'        => [$this->section->getId()->toRfc4122()],
            'plannedMonth' => '2026-10',
            'lead'         => $this->auditor->getId()->toRfc4122(),
            'auditors'     => $withAuditeeOnTeam ? [$this->auditee->getId()->toRfc4122()] : [],
        ]);
        preg_match('#/mejora/auditorias/([0-9a-f-]{36})#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        self::assertNotEmpty($m[1] ?? null);

        return $m[1];
    }

    public function testTheWholeAuditFromTheScreens(): void
    {
        $id = $this->plan();
        self::assertSame('AI-2026-01', $this->audit($id)->getCode());

        // The direction approves the programme.
        $this->loginAs($this->director, $this->centre);
        $this->client->request('GET', '/mejora/auditorias');
        self::assertStringContainsString('Aprobar el programa', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', '/mejora/auditorias/aprobar', ['_token' => $this->csrfToken('quality_audit_program')]);
        self::assertTrue($this->audit($id)->getProgram()->isApproved());

        // The auditor prepares it: a library checklist, the date, and starts.
        $this->loginAs($this->auditor, $this->centre);
        $this->client->request('GET', '/mejora/auditorias/' . $id . '/preparar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $template = $this->em->getRepository(AuditChecklistTemplate::class)->findOneBy([]);
        self::assertNotNull($template);
        $token = $this->csrfToken('quality_audit_' . $id);
        $this->client->request('POST', '/mejora/auditorias/' . $id . '/lista', ['_token' => $token, 'template' => $template->getId()->toRfc4122()]);
        $audit = $this->audit($id);
        self::assertSame(AuditStatus::Preparation, $audit->getStatus());
        $rows = [];
        foreach ($audit->getItems() as $item) {
            $rows[] = ['id' => $item->getId()->toRfc4122(), 'clause' => '8.1', 'question' => $item->getQuestion(), 'guidance' => ''];
        }
        $rows[] = ['id' => '', 'clause' => '8.1', 'question' => '¿Se registran las sustituciones?', 'guidance' => ''];
        $this->client->request('POST', '/mejora/auditorias/' . $id . '/preparar', ['_token' => $token, 'date' => '2026-10-20', 'time' => '09:30', 'objective' => 'Ver plazos', 'items' => $rows]);
        $this->client->request('POST', '/mejora/auditorias/' . $id . '/empezar', ['_token' => $token]);
        self::assertTrue($this->client->getResponse()->isRedirect('/mejora/auditorias/' . $id . '/realizar'));
        self::assertSame(AuditStatus::InProgress, $this->audit($id)->getStatus());
        self::assertCount(3, $this->audit($id)->getItems());

        // Carries it out on the tablet screen: results save as they're tapped.
        $this->client->request('GET', '/mejora/auditorias/' . $id . '/realizar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $component = $this->createLiveComponent('AuditPerformComponent', ['audit' => $this->audit($id)], $this->client)->actingAs($this->auditor);
        $component->call('setResult', ['index' => '0', 'result' => 'conforming']);
        $component->call('setResult', ['index' => '1', 'result' => 'nonconformity']);
        $component->set('evidence.1', 'Cuatro de doce, fuera de plazo.');
        $component->call('saveEvidence', ['index' => '1']);
        $component->call('setResult', ['index' => '2', 'result' => 'not_applicable']);
        $component->set('conclusion', 'Proceso adecuado, salvo los plazos.');
        $component->call('saveReport');
        // The live component test helper makes the client rethrow exceptions: back to responses.
        $this->client->catchExceptions(true);
        $audit = $this->audit($id);
        self::assertSame(3, $audit->countAnswered());
        self::assertSame('Cuatro de doce, fuera de plazo.', $audit->getItems()->get(1)?->getEvidence());

        // Some evidence, and the report.
        $itemId = $audit->getItems()->get(1)?->getId()->toRfc4122();
        $path   = tempnam(sys_get_temp_dir(), 'audit_');
        self::assertNotFalse($path);
        file_put_contents($path, 'captura');
        $this->client->request('POST', '/mejora/auditorias/puntos/' . $itemId . '/adjuntos', ['_token' => $this->csrfToken('quality_audit_item_' . $itemId)], ['files' => [new UploadedFile($path, 'captura.png', 'image/png', null, true)]]);
        self::assertCount(1, $this->audit($id)->getItems()->get(1)?->getAttachments() ?? []);

        $this->client->request('POST', '/mejora/auditorias/' . $id . '/emitir', ['_token' => $this->csrfToken('quality_audit_' . $id)]);
        self::assertSame(AuditStatus::ReportIssued, $this->audit($id)->getStatus());
        $finding = $this->em->getRepository(Finding::class)->findOneBy([]);
        self::assertSame('NC-2026-001', $finding?->getCode());

        // The report, for the auditee too (by email and on screen); not for anyone else.
        $this->loginAs($this->auditee, $this->centre);
        $this->client->request('GET', '/mejora/auditorias/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('NC-2026-001', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/auditorias/' . $id . '/informe.pdf');
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $this->loginAs($this->stranger, $this->centre);
        $this->client->request('GET', '/mejora/auditorias/' . $id);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSavingWarnsAboutAuditingOnesOwnProcess(): void
    {
        $this->plan(withAuditeeOnTeam: true);
        $this->client->followRedirect();
        self::assertStringContainsString('auditaría su propio proceso', (string) $this->client->getResponse()->getContent());
    }

    public function testWhoCanDoWhat(): void
    {
        $id = $this->plan();

        // The auditor sees the programme but neither plans nor approves.
        $this->loginAs($this->auditor, $this->centre);
        $this->client->request('GET', '/mejora/auditorias');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Aprobar el programa', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/auditorias/nueva');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/mejora/auditorias/aprobar', ['_token' => 'x']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // The auditee sees their audit, but can't prepare it.
        $this->loginAs($this->auditee, $this->centre);
        $this->client->request('GET', '/mejora/auditorias/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/mejora/auditorias/' . $id . '/preparar');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/mejora/auditorias');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Nor anyone else.
        $this->loginAs($this->stranger, $this->centre);
        foreach (['/mejora/auditorias', '/mejora/auditorias/' . $id, '/mejora/auditorias/listas'] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }
    }

    public function testTheChecklistLibrary(): void
    {
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora/auditorias/listas');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $token = $this->csrfToken('quality_checklists');

        $this->client->request('POST', '/mejora/auditorias/listas/iso', ['_token' => $token]);
        $this->client->followRedirect();
        self::assertMatchesRegularExpression('/Añadidas \d+ listas de la ISO 9001/', (string) $this->client->getResponse()->getContent());

        // Export them all, and import them back: same names get a number.
        $this->client->request('GET', '/mejora/auditorias/listas/exportar');
        $json = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('atica-calidad-audit-checklists', $json);
        $count = \count($this->em->getRepository(AuditChecklistTemplate::class)->findAll());
        $path  = tempnam(sys_get_temp_dir(), 'lists_');
        self::assertNotFalse($path);
        file_put_contents($path, $json);
        $this->client->request('GET', '/mejora/auditorias/listas');
        $this->client->request('POST', '/mejora/auditorias/listas/importar', ['_token' => $this->csrfToken('quality_checklists')], ['file' => new UploadedFile($path, 'listas.json', 'application/json', null, true)]);
        self::assertCount(2 * $count, $this->em->getRepository(AuditChecklistTemplate::class)->findAll());
        self::assertNotNull($this->em->getRepository(AuditChecklistTemplate::class)->findOneBy(['name' => '8.1 Planificación (2)']));

        // A file that isn't an export is refused.
        file_put_contents($path, '{"hola": 1}');
        $this->client->request('GET', '/mejora/auditorias/listas');
        $this->client->request('POST', '/mejora/auditorias/listas/importar', ['_token' => $this->csrfToken('quality_checklists')], ['file' => new UploadedFile($path, 'otro.json', 'application/json', null, true)]);
        $this->client->followRedirect();
        self::assertStringContainsString('no es una exportación de listas', (string) $this->client->getResponse()->getContent());

        // And the programme's PDF.
        $this->plan();
        $this->client->request('GET', '/mejora/auditorias/programa.pdf');
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }
}
