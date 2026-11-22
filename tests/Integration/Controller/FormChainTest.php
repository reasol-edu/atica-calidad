<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormApprovalStepState;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Repository\FormApprovalStepRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the second phase of the review adds to a filled document: an approval chain in ordered steps decided by profile holders, the
 * comments of reviewers, the comparison of two revisions, the edit lock (one person at a time) and the history of a value.
 */
final class FormChainTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private Folder $activityFolder;
    private SpecificProfile $authors;
    private SpecificProfile $dace;
    private SpecificProfile $studies;
    private Activity $activity;
    private Teacher $author;
    private Teacher $coauthor;
    private Teacher $daceHead;
    private Teacher $studiesHead;
    private Teacher $stranger;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre         = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section              = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $modelFolder          = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->activityFolder = (new Folder())->setDocumentSection($section)->setName('Entregas');
        $this->authors        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Profesorado');
        $this->dace           = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura DACE');
        $this->studies        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura de Estudios');
        $this->activityFolder->addUploadProfile($this->authors);
        $category       = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $this->activity = (new Activity())->setCategory($category)->setTitle('Salida')->setStart(1, 9)->setEnd(30, 6)->setFolder($this->activityFolder);

        $make = static fn (string $first, string $user): Teacher => (new Teacher(new PersonName($first, 'Apellido')))->setUsername($user);
        $this->author      = $make('Ana', 'autora');
        $this->coauthor    = $make('Cris', 'coautora');
        $this->daceHead    = $make('Dani', 'dace');
        $this->studiesHead = $make('Elena', 'estudios');
        $this->stranger    = $make('Fran', 'otro');

        $this->persist(
            $this->centre, $section, $modelFolder, $this->activityFolder, $this->authors, $this->dace, $this->studies, $category, $this->activity,
            $this->author, $this->coauthor, $this->daceHead, $this->studiesHead, $this->stranger,
            new SpecificProfileAssignment($this->authors, null, $this->author),
            new SpecificProfileAssignment($this->authors, null, $this->coauthor),
            new SpecificProfileAssignment($this->dace, null, $this->daceHead),
            new SpecificProfileAssignment($this->studies, null, $this->studiesHead),
        );
        $admin   = (new Teacher(new PersonName('Ada', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->persist($admin);
        $service = self::getContainer()->get(FormModelService::class);
        $model   = $service->create($modelFolder, 'Ficha', 'MD1');
        $service->saveDraft($model, $this->definition(), $model->getDraftVersion(), $admin);
        $service->sendToReview($model, $admin, null);
        $this->activity->setFormModel($model);
        $this->flush();
    }

    /** @return array<string, mixed> a title, a number, and an approval chain DACE → Jefatura de Estudios */
    private function definition(): array
    {
        $this->keys = ['title' => FormDefinition::newKey(), 'hours' => FormDefinition::newKey(), 'section' => FormDefinition::newKey()];

        return ['version' => 1, 'sections' => [[
            'key' => $this->keys['section'], 'title' => 'Datos', 'numbered' => true, 'help' => '', 'children' => [], 'blocks' => [
                ['key' => $this->keys['title'], 'type' => 'field', 'fieldType' => 'short', 'label' => 'Nombre', 'required' => true],
                ['key' => $this->keys['hours'], 'type' => 'field', 'fieldType' => 'number', 'label' => 'Horas'],
            ],
        ]], 'approval' => [
            ['name' => 'Jefatura del DACE', 'profile' => $this->dace->getId()->toRfc4122()],
            ['name' => 'Jefatura de Estudios', 'profile' => $this->studies->getId()->toRfc4122()],
        ]];
    }

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

    private function url(string $suffix = ''): string
    {
        return '/actividades/' . $this->activity->getId()->toRfc4122() . '/formulario/' . $this->authors->getId()->toRfc4122() . ':::' . $suffix;
    }

    private function cycle(): int
    {
        return self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($this->activity);
    }

    private function submissionId(): string
    {
        $this->em->clear();
        $submission = self::getContainer()->get(FormSubmissionRepository::class)->findBySlot($this->em->getRepository(Activity::class)->find($this->activity->getId()), $this->authors->getId()->toRfc4122() . ':::', $this->cycle());
        self::assertNotNull($submission);

        return $submission->getId()->toRfc4122();
    }

    /** @param array<string, mixed> $fields @return Response */
    private function save(string $sid, int $version, array $fields): Response
    {
        $this->client->request('POST', $this->url('/guardar'), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken('form_fill_' . $sid)], json_encode(['version' => $version, 'data' => ['fields' => $fields]], JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /** Opens the form as $teacher, fills it, delivers it and returns the id of the delivered revision. */
    private function deliver(Teacher $teacher, string $title, int $hours = 5): string
    {
        $this->loginAs($teacher, $this->centre);
        $this->client->request('GET', $this->url());
        $sid      = $this->submissionId();
        $version  = self::getContainer()->get(FormSubmissionRepository::class)->find($sid)->getDraftVersion();
        $response = $this->save($sid, $version, [$this->keys['title'] => $title, $this->keys['hours'] => $hours]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->client->request('POST', $this->url('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        $document = self::getContainer()->get(DocumentRepository::class)->findByFolder($this->em->getRepository(Folder::class)->find($this->activityFolder->getId()))[0];

        return $document->getRevisions()->last()->getId()->toRfc4122();
    }

    private function decide(Teacher $teacher, string $revisionId, string $decision, string $note = ''): Response
    {
        $this->loginAs($teacher, $this->centre);
        $this->client->request('GET', "/formularios/revision/{$revisionId}");

        return $this->post("/formularios/revision/{$revisionId}/decidir", $revisionId, ['decision' => $decision, 'note' => $note]);
    }

    /** @param array<string, string> $fields */
    private function post(string $url, string $revisionId, array $fields): Response
    {
        $this->client->request('POST', $url, ['_token' => $this->csrfToken('form_review_' . $revisionId)] + $fields);

        return $this->client->getResponse();
    }

    private function revision(string $id): DocumentRevision
    {
        $this->em->clear();

        return $this->em->getRepository(DocumentRevision::class)->find($id);
    }

    /** @return list<string> the states of the steps of a revision, in order */
    private function states(string $revisionId): array
    {
        $this->em->clear();
        $steps = self::getContainer()->get(FormApprovalStepRepository::class)->findByRevision($this->em->getRepository(DocumentRevision::class)->find($revisionId));

        return array_map(static fn ($s): string => $s->getState()->value, $steps);
    }

    // ── The approval chain ───────────────────────────────────────────────────

    public function testADocumentDeliveredWithAChainWaitsForItsStepsInOrder(): void
    {
        $rev = $this->deliver($this->author, 'Visita al museo');

        self::assertTrue($this->revision($rev)->isPendingReview(), 'the folder asks for no review, but the chain does');
        self::assertSame(['pending', 'waiting'], $this->states($rev));

        // Only whoever holds the profile of the current step decides it.
        self::assertSame(403, $this->decide($this->stranger, $rev, 'approve')->getStatusCode());
        self::assertSame(403, $this->decide($this->studiesHead, $rev, 'approve')->getStatusCode(), 'the second step cannot jump the first');
        self::assertSame(['pending', 'waiting'], $this->states($rev));

        self::assertTrue($this->decide($this->daceHead, $rev, 'approve', 'Conforme')->isRedirect());
        self::assertSame(['approved', 'pending'], $this->states($rev));
        self::assertTrue($this->revision($rev)->isPendingReview(), 'one step is not the revision');

        $this->decide($this->studiesHead, $rev, 'approve');
        self::assertSame(['approved', 'approved'], $this->states($rev));
        $approved = $this->revision($rev);
        self::assertFalse($approved->isPendingReview());
        self::assertSame($rev, $approved->getDocument()->getActiveRevision()?->getId()->toRfc4122(), 'the last approval puts it in force');
    }

    public function testARejectionNeedsAnExplanationEndsTheChainAndAStartsOverWithTheNextDelivery(): void
    {
        $rev = $this->deliver($this->author, 'Primera');
        $this->decide($this->daceHead, $rev, 'approve');

        $this->decide($this->studiesHead, $rev, 'reject', '');
        self::assertSame(['approved', 'pending'], $this->states($rev), 'a rejection without a reason does nothing');

        $this->decide($this->studiesHead, $rev, 'reject', 'Falta el presupuesto');
        self::assertSame(['approved', 'rejected'], $this->states($rev));
        self::assertTrue($this->revision($rev)->isRejected());

        // The correction is a new revision, and its chain starts from the first step.
        $this->loginAs($this->author, $this->centre);
        $this->client->request('POST', $this->url('/corregir'), ['_token' => $this->csrfToken('form_correct_' . $this->submissionId())]);
        $second = $this->deliver($this->author, 'Segunda');
        self::assertNotSame($rev, $second);
        self::assertSame(['pending', 'waiting'], $this->states($second));
    }

    public function testTheGenericReviewScreensCannotApproveAroundTheChain(): void
    {
        $rev  = $this->deliver($this->author, 'Visita');
        $doc  = $this->revision($rev)->getDocument();
        $this->loginAs($this->stranger, $this->centre);

        $this->client->request('POST', '/arbol-documental/carpetas/' . $this->activityFolder->getId()->toRfc4122() . '/documentos/' . $doc->getId()->toRfc4122() . '/revisiones/' . $rev . '/aprobar', [
            '_token' => $this->csrfToken('folder_document_revision_review_' . $rev),
        ]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403], 'denied or sent away, never approved');
        self::assertTrue($this->revision($rev)->isPendingReview());
        self::assertSame(['pending', 'waiting'], $this->states($rev));
    }

    public function testTheStepsWaitingForAPersonAreInTheirReviewQueue(): void
    {
        $this->deliver($this->author, 'Visita');

        $this->loginAs($this->daceHead, $this->centre);
        $this->client->request('GET', '/revisiones');
        self::assertStringContainsString('Paso «Jefatura del DACE»', (string) $this->client->getResponse()->getContent());

        $this->loginAs($this->studiesHead, $this->centre);
        $this->client->request('GET', '/revisiones');
        self::assertStringNotContainsString('Paso «Jefatura de Estudios»', (string) $this->client->getResponse()->getContent(), 'it is not their turn yet');
    }

    public function testAModelWhoseChainHasAStepWithoutProfileCannotBeSentToReview(): void
    {
        $admin = (new Teacher(new PersonName('Ada', 'Otra')))->setUsername('admin2')->setAdmin(true);
        $this->persist($admin);
        $folder  = $this->em->getRepository(Folder::class)->find($this->activityFolder->getId());
        $service = self::getContainer()->get(FormModelService::class);
        $model   = $service->create($folder, 'Otra', null);
        $def     = $this->definition();
        $def['approval'][1]['profile'] = '';
        $service->saveDraft($model, $def, $model->getDraftVersion(), $admin);

        try {
            $service->sendToReview($model, $admin, null);
            self::fail('a step without profile cannot be approved');
        } catch (\App\Service\Form\FormException $e) {
            self::assertContains('approval_step_without_profile', array_column($e->problems, 'code'));
        }
    }

    // ── Comments ─────────────────────────────────────────────────────────────

    public function testReviewersCommentOnPartsOfTheDocumentAndStrangersCannot(): void
    {
        $rev = $this->deliver($this->author, 'Visita');

        $this->loginAs($this->stranger, $this->centre);
        $this->client->request('GET', "/formularios/revision/{$rev}");
        // The folder is visible to the whole centre, so the page is: but a stranger to the document cannot comment on it.
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Comentar aquí', (string) $this->client->getResponse()->getContent());
        self::assertSame(403, $this->post("/formularios/revision/{$rev}/comentarios", $rev, ['anchor' => $this->keys['hours'], 'path' => $this->keys['section'], 'body' => 'Intruso'])->getStatusCode());

        $this->loginAs($this->daceHead, $this->centre);
        $this->client->request('GET', "/formularios/revision/{$rev}");
        self::assertStringContainsString('Comentar aquí', (string) $this->client->getResponse()->getContent());

        $this->post("/formularios/revision/{$rev}/comentarios", $rev, ['anchor' => $this->keys['hours'], 'path' => $this->keys['section'], 'body' => 'Revisa las horas']);
        $this->client->request('GET', "/formularios/revision/{$rev}");
        self::assertStringContainsString('Revisa las horas', (string) $this->client->getResponse()->getContent());

        // The author sees it and answers on the same part; a stranger to the document cannot add one.
        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', "/formularios/revision/{$rev}");
        self::assertStringContainsString('Revisa las horas', (string) $this->client->getResponse()->getContent());
        $this->post("/formularios/revision/{$rev}/comentarios", $rev, ['anchor' => $this->keys['hours'], 'path' => $this->keys['section'], 'body' => 'Corregido']);
        $this->client->request('GET', "/formularios/revision/{$rev}");
        self::assertStringContainsString('Corregido', (string) $this->client->getResponse()->getContent());
    }

    // ── Comparing revisions ──────────────────────────────────────────────────

    public function testTwoRevisionsAreComparedValueByValue(): void
    {
        $first = $this->deliver($this->author, 'Visita', 5);
        $this->decide($this->daceHead, $first, 'reject', 'Cambia las horas');

        $this->loginAs($this->author, $this->centre);
        $this->client->request('POST', $this->url('/corregir'), ['_token' => $this->csrfToken('form_correct_' . $this->submissionId())]);
        $second = $this->deliver($this->author, 'Visita', 8);

        $this->client->request('GET', "/formularios/revision/{$second}/comparar/{$first}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('De la versión 1 a la versión 2', $body, 'always from the older to the newer');
        self::assertStringContainsString('Horas', $body);
        self::assertStringNotContainsString('Nombre', substr($body, (int) strpos($body, '<tbody')), 'what did not change is not listed');
    }

    // ── Edit lock ────────────────────────────────────────────────────────────

    public function testOnlyOnePersonEditsADraftAtATimeAndAnotherCanTakeOver(): void
    {
        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', $this->url());
        $sid = $this->submissionId();
        self::assertSame(200, $this->save($sid, 1, [$this->keys['title'] => 'Mío'])->getStatusCode());

        // A co-author of the same profile finds it taken: read-only, and their saves are refused.
        $this->loginAs($this->coauthor, $this->centre);
        $this->client->request('GET', $this->url());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Lo está editando Ana', $body);
        self::assertStringContainsString('"readOnly":true', html_entity_decode($body));
        $response = $this->save($sid, 2, [$this->keys['title'] => 'Suyo']);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('error.locked', json_decode((string) $response->getContent(), true)['error']);

        // They take control; now it is the author who is refused.
        $this->client->request('POST', $this->url('/tomar'), ['_token' => $this->csrfToken('form_take_' . $sid)]);
        self::assertSame(200, $this->save($sid, 2, [$this->keys['title'] => 'Suyo'])->getStatusCode());
        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', $this->url());
        self::assertSame(409, $this->save($sid, 3, [$this->keys['title'] => 'Otra vez'])->getStatusCode());

        // Delivering also needs the lock.
        $this->client->request('POST', $this->url('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        $this->em->clear();
        self::assertSame([], self::getContainer()->get(DocumentRepository::class)->findByFolder($this->em->getRepository(Folder::class)->find($this->activityFolder->getId())));
    }

    public function testTheHeartbeatKeepsTheLockAndTellsWhenItWasLost(): void
    {
        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', $this->url());
        $sid = $this->submissionId();

        $beat = function (string $sid): int {
            $this->client->request('POST', $this->url('/latido'), [], [], ['HTTP_X_CSRF_TOKEN' => $this->csrfToken('form_fill_' . $sid)]);

            return $this->client->getResponse()->getStatusCode();
        };
        self::assertSame(200, $beat($sid));

        $this->loginAs($this->coauthor, $this->centre);
        self::assertSame(409, $beat($sid), 'the author still holds it');
    }

    // ── History ──────────────────────────────────────────────────────────────

    public function testEveryChangeOfAValueIsRecordedAndTypingIsOneEntry(): void
    {
        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', $this->url());
        $sid = $this->submissionId();

        $this->save($sid, 1, [$this->keys['title'] => 'V']);
        $this->save($sid, 2, [$this->keys['title'] => 'Vis']);
        $this->save($sid, 3, [$this->keys['title'] => 'Visita']);

        $this->client->request('GET', $this->url('/historial') . '?block=' . $this->keys['title'] . '&path=' . $this->keys['section']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $changes = json_decode((string) $this->client->getResponse()->getContent(), true)['changes'];
        self::assertCount(1, $changes, 'three keystrokes in a row are one change');
        self::assertNull($changes[0]['old']);
        self::assertSame('Visita', $changes[0]['new']);
        self::assertSame('Ana Apellido', $changes[0]['by']);

        // Somebody else changes it: a new entry that remembers what it was.
        $this->loginAs($this->coauthor, $this->centre);
        $this->client->request('POST', $this->url('/tomar'), ['_token' => $this->csrfToken('form_take_' . $sid)]);
        $this->save($sid, 4, [$this->keys['title'] => 'Visita al museo']);
        $this->client->request('GET', $this->url('/historial') . '?block=' . $this->keys['title'] . '&path=' . $this->keys['section']);
        $changes = json_decode((string) $this->client->getResponse()->getContent(), true)['changes'];
        self::assertCount(2, $changes);
        self::assertSame('Visita', $changes[0]['old']);
        self::assertSame('Visita al museo', $changes[0]['new']);
        self::assertSame('Cris Apellido', $changes[0]['by']);
    }
}
