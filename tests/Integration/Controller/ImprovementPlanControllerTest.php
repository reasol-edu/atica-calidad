<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionStatus;
use App\Entity\ImprovementActionType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\ImprovementActionRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** The improvement plan from the screens: the quality manager plans, the responsible does it, others can't touch it. */
final class ImprovementPlanControllerTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $manager;
    private Teacher $responsible;
    private Teacher $auditor;
    private Teacher $stranger;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function reload(string $id): ?ImprovementAction
    {
        $this->em->clear();
        /** @var ImprovementActionRepository $actions */
        $actions = self::getContainer()->get(ImprovementActionRepository::class);

        return $actions->find($id);
    }

    /** The manager adds an action for $this->responsible; returns its id. */
    private function addAction(string $description = 'Preparar una guía de acogida'): string
    {
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora/plan/nueva');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/mejora/plan/nueva', [
            '_token'      => $this->csrfToken('quality_plan_action'),
            'type'        => 'improvement',
            'description' => $description,
            'goal'        => 'Que el profesorado nuevo conozca el SGC en su primera semana.',
            'section'     => '',
            'responsible' => 't:' . $this->responsible->getId()->toRfc4122(),
            'dueDate'     => '2026-11-30',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        preg_match('#/mejora/acciones/([0-9a-f-]{36})#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        self::assertNotEmpty($m[1] ?? null);

        return $m[1];
    }

    public function testTheManagerPlansAndTheResponsibleCarriesItOut(): void
    {
        $id     = $this->addAction();
        $action = $this->reload($id);
        self::assertNotNull($action);
        self::assertMatchesRegularExpression('/^PM-\d{4}-001$/', (string) $action->getCode());
        self::assertTrue($action->isPlanAction());
        self::assertSame('2026-2027', $action->getAcademicYear()?->getName());
        self::assertSame($this->responsible->getId()->toRfc4122(), $action->getResponsibleTeacher()?->getId()->toRfc4122());

        // It's on the plan.
        $this->client->request('GET', '/mejora/plan');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Preparar una guía de acogida', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('0 de 1 acciones hechas', (string) $this->client->getResponse()->getContent());

        // The responsible has it in "Lo que toca ahora", opens it, starts it, adds evidence and marks it done.
        $this->loginAs($this->responsible, $this->centre);
        $this->client->request('GET', '/mejora');
        self::assertStringContainsString('/mejora/acciones/' . $id, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/mejora/acciones/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Marcar como hecha', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Eliminar', (string) $this->client->getResponse()->getContent());

        $token = $this->csrfToken('quality_action_' . $id);
        $this->client->request('POST', '/mejora/acciones/' . $id . '/empezar', ['_token' => $token]);
        self::assertSame(ImprovementActionStatus::InProgress, $this->reload($id)?->getStatus());

        $path = tempnam(sys_get_temp_dir(), 'plan_test_');
        self::assertNotFalse($path);
        file_put_contents($path, 'la guía');
        $this->client->request('POST', '/mejora/acciones/' . $id . '/adjuntos', ['_token' => $this->csrfToken('quality_attach_' . $id)], [
            'files' => [new UploadedFile($path, 'guia.txt', 'text/plain', null, true)],
        ]);
        self::assertStringEndsWith('/mejora/acciones/' . $id . '#evidencias', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('POST', '/mejora/acciones/' . $id . '/hecha', ['_token' => $token, 'result' => 'Guía publicada en la carpeta de acogida.']);
        $action = $this->reload($id);
        self::assertNotNull($action);
        self::assertTrue($action->isDone());
        self::assertSame('Guía publicada en la carpeta de acogida.', $action->getResult());
        self::assertCount(1, $action->getAttachments());

        // The evidence can be downloaded by whoever sees the action — the auditor too, not a stranger.
        $attachmentId = $action->getAttachments()->first()->getId()->toRfc4122();
        $this->client->request('GET', '/mejora/adjuntos/' . $attachmentId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->loginAs($this->auditor, $this->centre);
        $this->client->request('GET', '/mejora/adjuntos/' . $attachmentId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->loginAs($this->stranger, $this->centre);
        $this->client->request('GET', '/mejora/adjuntos/' . $attachmentId);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCodesGoOnAndTheFormRejectsWhatItMust(): void
    {
        $this->addAction('Primera');
        $second = $this->reload($this->addAction('Segunda'));
        self::assertStringEndsWith('-002', (string) $second?->getCode());

        $this->client->request('GET', '/mejora/plan/nueva');
        $this->client->request('POST', '/mejora/plan/nueva', [
            '_token'      => $this->csrfToken('quality_plan_action'),
            'type'        => 'corrective', // a finding's type, not a plan's
            'description' => '',
            'responsible' => 't:' . $this->stranger->getId()->toRfc4122() . 'x',
            'dueDate'     => '',
        ]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Elige el tipo de acción.', $content);
        self::assertStringContainsString('Describe la acción.', $content);
        self::assertStringContainsString('Pon una fecha de plazo.', $content);
    }

    public function testTheManagerEditsAndDeletes(): void
    {
        $id = $this->addAction();

        $this->client->request('GET', '/mejora/acciones/' . $id . '/editar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/mejora/acciones/' . $id . '/editar', [
            '_token'      => $this->csrfToken('quality_plan_action'),
            'type'        => 'preventive',
            'description' => 'Guía de acogida, revisada',
            'goal'        => '',
            'section'     => '',
            'responsible' => '',
            'dueDate'     => '2026-12-15',
        ]);
        $action = $this->reload($id);
        self::assertSame(ImprovementActionType::Preventive, $action?->getType());
        self::assertSame('Guía de acogida, revisada', $action?->getDescription());
        self::assertNull($action?->getGoal());
        // "Yo" is whoever edits.
        self::assertSame($this->manager->getId()->toRfc4122(), $action?->getResponsibleTeacher()?->getId()->toRfc4122());

        $this->client->request('GET', '/mejora/acciones/' . $id);
        $this->client->request('POST', '/mejora/acciones/' . $id . '/eliminar', ['_token' => $this->csrfToken('quality_action_' . $id)]);
        self::assertTrue($this->client->getResponse()->isRedirect('/mejora/plan'));
        self::assertNull($this->reload($id));
    }

    public function testWhoCanSeeAndDoWhat(): void
    {
        $id = $this->addAction();

        // The auditor sees the plan and the action, but can't add, edit or carry it out.
        $this->loginAs($this->auditor, $this->centre);
        $this->client->request('GET', '/mejora/plan');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('/mejora/plan/nueva', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/acciones/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Marcar como hecha', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/plan/nueva');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/mejora/acciones/' . $id . '/editar');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Anyone else: nothing.
        $this->loginAs($this->stranger, $this->centre);
        foreach (['/mejora/plan', '/mejora/acciones/' . $id] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }
        $this->client->request('POST', '/mejora/acciones/' . $id . '/hecha', ['_token' => 'x', 'result' => 'Hecho']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->reload($id)?->isDone());
    }

    public function testTheReportInBothFormats(): void
    {
        $this->addAction();
        $base = '/centro/' . $this->centre->getId()->toRfc4122() . '/informes/plan-de-mejora';

        $this->client->request('GET', $base . '.xlsx');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->request('GET', $base . '.pdf?curso=' . $this->year->getId()->toRfc4122());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->request('GET', $base . '.pdf?curso=nope');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/centro/' . $this->centre->getId()->toRfc4122() . '/informes');
        self::assertStringContainsString('Plan de mejora', (string) $this->client->getResponse()->getContent());
    }
}
