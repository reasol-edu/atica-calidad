<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ImprovementAction;
use App\Entity\ManagementReview;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** The management review from the screens: the quality manager prepares it and records its decisions, the direction closes it. */
final class ManagementReviewControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $manager;
    private Teacher $director;
    private Teacher $auditor;
    private Teacher $stranger;

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
        $this->stranger = $this->teacher('otro', 'Olga');
        $this->centre->addQualityManager($this->manager);
        $this->centre->addAdmin($this->director);
        $this->centre->addInternalAuditor($this->auditor);

        $this->persist($this->centre, $this->year, $this->manager, $this->director, $this->auditor, $this->stranger);
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

    private function review(string $id): ManagementReview
    {
        $this->em->clear();
        $review = $this->em->find(ManagementReview::class, $id);
        self::assertNotNull($review);

        return $review;
    }

    /** The manager schedules it with the suggested values; returns its id. */
    private function schedule(): string
    {
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora/revisiones/nueva');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Revisión por la dirección 2026-2027', $content);
        self::assertStringContainsString('value="2026-09-01"', $content);
        $this->client->request('POST', '/mejora/revisiones/nueva', [
            '_token'      => $this->csrfToken('quality_review'),
            'title'       => 'Revisión de octubre',
            'heldOn'      => '2026-10-05',
            'periodStart' => '2026-09-01',
            'periodEnd'   => '2026-10-05',
        ]);
        preg_match('#/mejora/revisiones/([0-9a-f-]{36})#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        self::assertNotEmpty($m[1] ?? null);

        return $m[1];
    }

    public function testTheWholeReviewFromTheScreens(): void
    {
        $id = $this->schedule();

        // It shows what the application knows, and what's still missing to close it.
        $this->client->request('GET', '/mejora/revisiones/' . $id);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Es la primera revisión por la dirección.', $content);
        self::assertStringContainsString('Faltan las conclusiones.', $content);
        self::assertStringNotContainsString('Cerrar la revisión', $content);

        // The meeting's notes.
        $this->client->request('GET', '/mejora/revisiones/' . $id . '/editar');
        $this->client->request('POST', '/mejora/revisiones/' . $id . '/editar', [
            '_token'      => $this->csrfToken('quality_review'),
            'title'       => 'Revisión de octubre',
            'heldOn'      => '2026-10-05',
            'periodStart' => '2026-09-01',
            'periodEnd'   => '2026-10-05',
            'attendees'   => 'Dirección y calidad',
            'resources'   => 'Falta un aula de informática.',
            'conclusions' => 'El sistema es adecuado y eficaz.',
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect('/mejora/revisiones/' . $id));
        self::assertSame('Falta un aula de informática.', $this->review($id)->getResources());

        // A decision, through the plan's form: it goes back to the review.
        $this->client->request('GET', '/mejora/plan/nueva?revision=' . $id);
        self::assertStringContainsString('Decisión de «Revisión de octubre»', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', '/mejora/plan/nueva', [
            '_token'      => $this->csrfToken('quality_plan_action'),
            'review'      => $id,
            'type'        => 'improvement',
            'description' => 'Pedir un aula de informática',
            'goal'        => '',
            'section'     => '',
            'responsible' => 't:' . $this->director->getId()->toRfc4122(),
            'dueDate'     => '2026-12-01',
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect('/mejora/revisiones/' . $id . '#decisiones'));
        $decision = $this->em->getRepository(ImprovementAction::class)->findOneBy(['description' => 'Pedir un aula de informática']);
        self::assertSame($id, $decision?->getManagementReview()?->getId()->toRfc4122());

        // The manager can't close it; the direction can.
        $this->client->request('POST', '/mejora/revisiones/' . $id . '/cerrar', ['_token' => $this->csrfToken('quality_review_' . $id)]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->loginAs($this->director, $this->centre);
        $this->client->request('GET', '/mejora/revisiones/' . $id);
        self::assertStringContainsString('Cerrar la revisión', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', '/mejora/revisiones/' . $id . '/cerrar', ['_token' => $this->csrfToken('quality_review_' . $id)]);
        $review = $this->review($id);
        self::assertTrue($review->isClosed());
        self::assertNotNull($review->getSnapshot());

        // Closed: the minutes, and no more changes.
        $this->client->request('GET', '/mejora/revisiones/' . $id . '/acta.pdf');
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $this->client->request('GET', '/mejora/revisiones/' . $id . '/editar');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/mejora/plan/nueva?revision=' . $id);
        self::assertStringNotContainsString('Decisión de «Revisión de octubre»', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The six texts are HTML from the Quill rich-text editor (see Form:RichEditor), like
     * Folder::$description: stored raw, sanitized with app.rich_text on every render — the
     * review page and the PDF alike — never on write.
     */
    public function testTheTextsSupportRichTextAndAreSanitizedOnRender(): void
    {
        $id = $this->schedule();
        $this->client->request('GET', '/mejora/revisiones/' . $id . '/editar');
        $this->client->request('POST', '/mejora/revisiones/' . $id . '/editar', [
            '_token'      => $this->csrfToken('quality_review'),
            'title'       => 'Revisión de octubre',
            'heldOn'      => '2026-10-05',
            'periodStart' => '2026-09-01',
            'periodEnd'   => '2026-10-05',
            'attendees'   => '<p><strong>Dirección</strong> y calidad</p><ul><li>Ana</li><li>Pablo</li></ul>',
            'conclusions' => '<p onclick="alert(1)">El sistema es <script>alert(1)</script>adecuado</p><img src=x onerror=alert(1)>',
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect('/mejora/revisiones/' . $id));

        // Stored exactly as submitted — sanitizing happens on render, never on write.
        $review = $this->review($id);
        self::assertStringContainsString('<script>', (string) $review->getConclusions());

        $this->client->request('GET', '/mejora/revisiones/' . $id);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<strong>Dirección</strong>', $content);
        self::assertStringContainsString('<li>Ana</li>', $content);
        // The disallowed tag/attribute are stripped (the layout's own unrelated <script> for flash
        // messages is always there, so it's the payload itself, not the tag name, that must be gone);
        // the safe text around them survives.
        self::assertStringNotContainsString('alert(1)', $content);
        self::assertStringNotContainsString('onclick=', $content);
        self::assertStringContainsString('El sistema es', $content);
        self::assertStringContainsString('adecuado', $content);

        // The same sanitizer is applied in the PDF template.
        $this->client->request('GET', '/mejora/revisiones/' . $id . '/acta.pdf');
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testWhoCanDoWhat(): void
    {
        $id = $this->schedule();

        // The auditor reads it, but neither prepares it nor adds reviews.
        $this->loginAs($this->auditor, $this->centre);
        $this->client->request('GET', '/mejora/revisiones');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Nueva revisión', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/mejora/revisiones/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        foreach (['/mejora/revisiones/nueva', '/mejora/revisiones/' . $id . '/editar'] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }

        // Nobody else sees it.
        $this->loginAs($this->stranger, $this->centre);
        foreach (['/mejora/revisiones', '/mejora/revisiones/' . $id, '/mejora/revisiones/' . $id . '/acta.pdf'] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }

        // An open review can be deleted.
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora/revisiones/' . $id);
        $this->client->request('POST', '/mejora/revisiones/' . $id . '/eliminar', ['_token' => $this->csrfToken('quality_review_' . $id)]);
        self::assertTrue($this->client->getResponse()->isRedirect('/mejora/revisiones'));
        self::assertSame([], $this->em->getRepository(ManagementReview::class)->findAll());
    }

    public function testThePeriodMustMakeSense(): void
    {
        $this->loginAs($this->manager, $this->centre);
        $this->client->request('GET', '/mejora/revisiones/nueva');
        $this->client->request('POST', '/mejora/revisiones/nueva', [
            '_token'      => $this->csrfToken('quality_review'),
            'title'       => '',
            'heldOn'      => '2026-10-05',
            'periodStart' => '2026-10-01',
            'periodEnd'   => '2026-09-01',
        ]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ponle un título.', $content);
        self::assertStringContainsString('no puede ser anterior al principio', $content);
    }
}
