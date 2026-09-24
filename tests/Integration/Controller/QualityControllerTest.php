<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingStatus;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\FindingRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** "Mejora continua" from the screens, with each profile: who reports, who analyses, who manages. */
final class QualityControllerTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private EducationalCentre $centre;
    private Teacher $manager;
    private Teacher $reporter;
    private Teacher $analyst;
    private Teacher $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year         = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($year);
        $this->manager  = $this->teacher('calidad', 'Laura', $year);
        $this->reporter = $this->teacher('docente', 'Luis', $year);
        $this->analyst  = $this->teacher('jefa', 'Ana', $year);
        $this->stranger = $this->teacher('otro', 'Olga', $year);
        $this->centre->addQualityManager($this->manager);
        $this->persist($this->centre, $year, $this->manager, $this->reporter, $this->analyst, $this->stranger);
    }

    private function teacher(string $username, string $first, AcademicYear $year): Teacher
    {
        $teacher = (new Teacher(new PersonName($first, ucfirst($username))))->setUsername($username);
        $teacher->addAcademicYear($year);

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

    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'quality_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    private function reload(string $id): Finding
    {
        $this->em->clear();
        /** @var FindingRepository $findings */
        $findings = self::getContainer()->get(FindingRepository::class);
        $finding  = $findings->find($id);
        self::assertNotNull($finding);

        return $finding;
    }

    /** The reporter reports an incident with a photo; returns its id. */
    private function report(): string
    {
        $this->loginAs($this->reporter, $this->centre);
        $this->client->request('GET', '/mejora/comunicar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/mejora/comunicar', [
            '_token'      => $this->csrfToken('quality_report'),
            'description' => "El proyector del aula 12 no funciona\nDesde hace dos semanas.",
            'section'     => '',
        ], ['files' => [$this->file('foto.txt', 'foto del proyector')]]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        preg_match('#/mejora/fichas/([0-9a-f-]{36})#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        self::assertNotEmpty($m[1] ?? null);

        return $m[1];
    }

    public function testTheWholeCycleFromTheScreens(): void
    {
        $id = $this->report();

        // The reporter sees it as reported, with its photo, in "Lo que has comunicado".
        $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('El proyector del aula 12 no funciona', $content);
        self::assertStringContainsString('foto.txt', $content);
        self::assertStringContainsString('La coordinación de calidad tiene que revisarla', $content);
        $this->client->request('GET', '/mejora');
        self::assertStringContainsString('El proyector del aula 12 no funciona', (string) $this->client->getResponse()->getContent());

        // The quality manager: it's in the inbox; classifies it as a nonconformity for the analyst.
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora');
        self::assertStringContainsString('Bandeja: por clasificar', (string) $this->client->getResponse()->getContent());
        $component = $this->createLiveComponent('FindingDetailComponent', ['finding' => $this->reload($id)], $this->client);
        $component->set('kind', 'nonconformity')->set('severity', 'minor')->set('title', 'Partes de avería sin atender')
            ->set('responsibleId', $this->analyst->getId()->toRfc4122())->set('analysisDueDate', '2026-12-01')
            ->call('classify');
        $finding = $this->reload($id);
        self::assertSame(FindingStatus::Analysis, $finding->getStatus());
        self::assertMatchesRegularExpression('/^NC-\d{4}-001$/', (string) $finding->getCode());

        // The analyst: it's in "Tus próximos pasos"; analyses, adds a corrective action for the reporter, finishes.
        $this->loginAs($this->analyst, $this->centre);
        $this->client->request('GET', '/');
        self::assertStringContainsString('Analizar causas: Partes de avería sin atender', (string) $this->client->getResponse()->getContent());
        $component = $this->createLiveComponent('FindingDetailComponent', ['finding' => $this->reload($id)], $this->client);
        $component->set('whys.0', 'Nadie revisa los partes')->set('rootCause', 'No hay responsable de los partes');
        $component->call('toggleAddAction');
        $component->set('actionType', 'corrective')->set('actionDescription', 'Revisión semanal de partes')
            ->set('actionResponsible', 't:' . $this->reporter->getId()->toRfc4122())->set('actionDueDate', '2026-12-15')
            ->call('addAction');
        $component->call('submitAnalysis');
        $finding = $this->reload($id);
        self::assertSame(FindingStatus::Execution, $finding->getStatus());
        self::assertSame(['Nadie revisa los partes'], $finding->getWhys());
        $actionId = $finding->getActions()->first()?->getId()->toRfc4122();
        self::assertNotNull($actionId);

        // The reporter does the action: the finding moves to verification on its own.
        $this->loginAs($this->reporter, $this->centre);
        $this->client->request('GET', '/');
        self::assertStringContainsString('Hacer: Revisión semanal de partes', (string) $this->client->getResponse()->getContent());
        $component = $this->createLiveComponent('FindingDetailComponent', ['finding' => $this->reload($id)], $this->client);
        $component->call('toggleCompleteAction', ['id' => $actionId]);
        $component->set('completeResult', 'Asignada a la secretaría')->call('completeAction');
        self::assertSame(FindingStatus::Verification, $this->reload($id)->getStatus());

        // The quality manager verifies it: closed.
        $this->loginAs($this->manager, $this->centre);
        $component = $this->createLiveComponent('FindingDetailComponent', ['finding' => $this->reload($id)], $this->client);
        $component->set('verifyNotes', 'Un mes sin partes pendientes')->call('verify', ['outcome' => 'effective']);
        $finding = $this->reload($id);
        self::assertSame(FindingStatus::Closed, $finding->getStatus());
        self::assertTrue($finding->isEffective());
        self::assertStringContainsString('Verificada: eficaz', $component->render()->toString());
    }

    public function testFinishingTheAnalysisExplainsWhatIsMissing(): void
    {
        $id = $this->report();
        $this->loginAs($this->manager, $this->centre);
        $component = $this->createLiveComponent('FindingDetailComponent', ['finding' => $this->reload($id)], $this->client);
        $component->set('kind', 'nonconformity')->set('responsibleId', $this->analyst->getId()->toRfc4122())->call('classify');

        $this->loginAs($this->analyst, $this->centre);
        $component = $this->createLiveComponent('FindingDetailComponent', ['finding' => $this->reload($id)], $this->client);
        $html = $component->render()->toString();
        self::assertStringContainsString('Falta escribir la causa raíz.', $html);
        self::assertStringContainsString('Añade al menos una acción correctiva.', $html);

        $component->call('submitAnalysis');
        self::assertSame(FindingStatus::Analysis, $this->reload($id)->getStatus());
        self::assertStringContainsString('Falta escribir la causa raíz.', $component->render()->toString());
    }

    public function testOnlyTheQualityManagerClassifies(): void
    {
        $id = $this->report();
        $component = $this->createLiveComponent('FindingDetailComponent', ['finding' => $this->reload($id)], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->set('kind', 'observation')->call('classify');
    }

    public function testDiscardingTellsWhy(): void
    {
        $id = $this->report();
        $this->loginAs($this->manager, $this->centre);
        $component = $this->createLiveComponent('FindingDetailComponent', ['finding' => $this->reload($id)], $this->client);
        $component->call('toggleDiscard');
        $component->call('discard');
        self::assertStringContainsString('Explica por qué se descarta.', $component->render()->toString());

        $component->set('discardReason', 'No depende del centro')->call('discard');
        self::assertSame(FindingStatus::Discarded, $this->reload($id)->getStatus());
    }

    public function testWhoSeesWhat(): void
    {
        $id = $this->report();

        // Someone unrelated can't open it, nor the full list.
        $this->loginAs($this->stranger, $this->centre);
        $this->client->request('GET', '/mejora/fichas/' . $id);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/mejora/fichas');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        // …but still has their own hub, to report.
        $this->client->request('GET', '/mejora');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Bandeja', (string) $this->client->getResponse()->getContent());

        // The quality manager sees the list.
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora/fichas');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('El proyector del aula 12 no funciona', (string) $this->client->getResponse()->getContent());
    }

    public function testAttachmentsAreDownloadedOnlyByWhoCanSeeTheFinding(): void
    {
        $id         = $this->report();
        $attachment = $this->reload($id)->getAttachments()->first();
        self::assertNotFalse($attachment);
        $url = '/mejora/adjuntos/' . $attachment->getId()->toRfc4122();

        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('foto del proyector', $this->client->getResponse()->getContent());

        $this->loginAs($this->stranger, $this->centre);
        $this->client->request('GET', $url);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheNonconformityReport(): void
    {
        $this->report();
        $centreId = $this->centre->getId()->toRfc4122();

        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', "/centro/{$centreId}/informes");
        self::assertStringContainsString('1 abierta · 0 cerradas.', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', "/centro/{$centreId}/informes/no-conformidades.pdf");
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->request('GET', "/centro/{$centreId}/informes/no-conformidades.xlsx");
        self::assertSame(200, $this->client->getInternalResponse()->getStatusCode());
        self::assertStringContainsString('no-conformidades-', (string) $this->client->getInternalResponse()->getHeader('Content-Disposition'));
    }

    public function testAReportNeedsADescription(): void
    {
        $this->loginAs($this->reporter, $this->centre);
        $this->client->request('GET', '/mejora/comunicar');
        $this->client->request('POST', '/mejora/comunicar', ['_token' => $this->csrfToken('quality_report'), 'description' => '   ']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Cuenta qué ha pasado.', (string) $this->client->getResponse()->getContent());
    }
}
