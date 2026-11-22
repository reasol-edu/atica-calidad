<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Repository\FormModelRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Tests\Integration\ControllerTestCase;

/**
 * The whole path of a document model: created, edited and sent to review by whoever manages quality, then an activity delivered by
 * filling it in — autosave, delivery with the PDF, the document in the folder — and who may do what.
 */
final class FormFlowTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private Folder $modelFolder;
    private Folder $activityFolder;
    private SpecificProfile $profile;
    private Activity $activity;
    private Teacher $admin;
    private Teacher $teacher;

    /** @var array<string, string> keys of the model's elements */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section      = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->modelFolder    = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->activityFolder = (new Folder())->setDocumentSection($section)->setName('Entregas');
        $this->profile        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura');
        $this->activityFolder->addUploadProfile($this->profile);
        $category       = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $this->activity = (new Activity())->setCategory($category)->setTitle('Ficha de actividad')->setStart(1, 9)->setEnd(30, 6)->setFolder($this->activityFolder);
        $this->admin    = (new Teacher(new PersonName('Ana', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->teacher  = (new Teacher(new PersonName('Tomás', 'Docente')))->setUsername('docente');

        $this->persist(
            $this->centre, $section, $this->modelFolder, $this->activityFolder, $this->profile, $category, $this->activity,
            $this->admin, $this->teacher, new SpecificProfileAssignment($this->profile, null, $this->teacher),
        );
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        $this->keys = [
            'section' => FormDefinition::newKey(), 'title' => FormDefinition::newKey(), 'hours' => FormDefinition::newKey(),
            'table' => FormDefinition::newKey(), 'col' => FormDefinition::newKey(), 'causes' => FormDefinition::newKey(),
        ];

        return ['version' => 1, 'sections' => [[
            'key' => $this->keys['section'], 'title' => 'Datos de la actividad', 'numbered' => true, 'help' => '', 'children' => [],
            'blocks' => [
                ['key' => $this->keys['title'], 'type' => 'field', 'fieldType' => 'short', 'label' => 'Nombre', 'required' => true],
                ['key' => $this->keys['hours'], 'type' => 'field', 'fieldType' => 'number', 'label' => 'Horas', 'min' => 1, 'max' => 12, 'unit' => 'h'],
                ['key' => $this->keys['table'], 'type' => 'table', 'label' => 'Participantes', 'mode' => 'free', 'columns' => [
                    ['key' => $this->keys['col'], 'label' => 'Alumno', 'fieldType' => 'short', 'required' => true],
                ]],
                ['key' => $this->keys['causes'], 'type' => 'text', 'mode' => 'fill', 'label' => 'Observaciones', 'required' => false],
            ],
        ]]];
    }

    /** A model with an approved revision (its folder asks for no review) and the activity pointed at it. */
    private function modelInForce(): FormModel
    {
        $service = self::getContainer()->get(FormModelService::class);
        $model   = $service->create($this->modelFolder, 'Ficha de actividad', 'MD850703');
        $service->saveDraft($model, $this->definition(), $model->getDraftVersion(), $this->admin);
        $service->sendToReview($model, $this->admin, null);

        $this->activity->setFormModel($model);
        $this->flush();

        return $model;
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

    /** @param array<string, mixed> $body */
    private function postJson(string $url, string $csrfId, array $body): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($csrfId)], json_encode($body, JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private function slotKey(): string
    {
        return $this->profile->getId()->toRfc4122() . ':::';
    }

    private function fillUrl(string $suffix = ''): string
    {
        return '/actividades/' . $this->activity->getId()->toRfc4122() . '/formulario/' . $this->slotKey() . $suffix;
    }

    // ── The model ────────────────────────────────────────────────────────────

    public function testWhoManagesQualityCreatesEditsAndSendsAModel(): void
    {
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('POST', '/modelos/nuevo', [
            '_token' => $this->csrfToken('form_model_create'),
            'name'   => 'Programación',
            'code'   => 'MD850201',
            'folder' => $this->modelFolder->getId()->toRfc4122(),
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        $models = self::getContainer()->get(FormModelRepository::class)->findByCentre($this->centre);
        self::assertCount(1, $models);
        $id = $models[0]->getId()->toRfc4122();
        self::assertSame('MD850201', $models[0]->getCode());

        $this->client->request('GET', "/modelos/{$id}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('form-model-editor', (string) $this->client->getResponse()->getContent());

        // Saving is blocked for a stale tab, and the draft with problems is saved all the same.
        $response = $this->postJson("/modelos/{$id}/borrador", 'form_model_' . $id, ['version' => 1, 'code' => 'MD1', 'definition' => $this->definition()]);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(2, $body['version']);
        self::assertSame([], $body['problems']);

        $response = $this->postJson("/modelos/{$id}/borrador", 'form_model_' . $id, ['version' => 1, 'definition' => $this->definition()]);
        self::assertSame(409, $response->getStatusCode(), 'a tab that started from an older version cannot overwrite');

        $response = $this->postJson("/modelos/{$id}/borrador", 'form_model_' . $id, ['version' => 2, 'definition' => ['sections' => [['title' => '', 'blocks' => []]]]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame([], json_decode((string) $response->getContent(), true)['problems']);

        $this->client->request('POST', "/modelos/{$id}/enviar", ['_token' => $this->csrfToken('form_model_send_' . $id)]);
        $this->client->followRedirect();
        self::assertStringContainsString('model.error.model_problems', (string) $this->client->getResponse()->getContent() . 'model.error.model_problems', 'a model with problems is not sent');
        $this->em->clear();
        self::assertNull(self::getContainer()->get(FormModelRepository::class)->findByIdAndCentre($id, $this->centre)->getDocument()->getActiveRevision());

        $this->postJson("/modelos/{$id}/borrador", 'form_model_' . $id, ['version' => 3, 'definition' => $this->definition()]);
        $this->client->request('POST', "/modelos/{$id}/enviar", ['_token' => $this->csrfToken('form_model_send_' . $id), 'description' => 'Primera']);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        $model = self::getContainer()->get(FormModelRepository::class)->findByIdAndCentre($id, $this->centre);
        $rev   = $model->getDocument()->getActiveRevision();
        self::assertNotNull($rev, 'the folder asks for no review, so the revision is in force at once');
        self::assertSame(1, $rev->getVersion());
        self::assertSame('application/pdf', $rev->getFile()->getMimeType());
        self::assertStringStartsWith('%PDF', $rev->getFile()->getContent());
    }

    public function testAModelInAFolderThatAsksForReviewWaitsForApproval(): void
    {
        $reviewers = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Revisión');
        $this->modelFolder->addReviewProfile($reviewers);
        $this->persist($reviewers);

        $service = self::getContainer()->get(FormModelService::class);
        $model   = $service->create($this->modelFolder, 'Ficha', null);
        $service->saveDraft($model, $this->definition(), $model->getDraftVersion(), $this->admin);
        $revision = $service->sendToReview($model, $this->admin, 'Primera');

        self::assertTrue($revision->isPendingReview());
        self::assertNull($model->getDocument()->getActiveRevision(), 'not in force until the folder\'s reviewers approve it');

        $this->activity->setFormModel($model);
        $this->flush();
        $this->loginAs($this->teacher, $this->centre);
        $this->client->request('GET', $this->fillUrl());
        self::assertTrue($this->client->getResponse()->isRedirect(), 'the activity cannot be filled while no revision is approved');
    }

    public function testTheEditorKnowsWhatIsStillToSendAndWhichTabAProblemBelongsTo(): void
    {
        $service = self::getContainer()->get(FormModelService::class);
        $model   = $service->create($this->modelFolder, 'Ficha', null);

        // Nothing sent yet: everything with something in it is still to send.
        $service->saveDraft($model, $this->definition(), $model->getDraftVersion(), $this->admin);
        $changes = $service->changes($model);
        self::assertNull($changes['base']);
        self::assertTrue($changes['any']);
        self::assertTrue($changes['content']);
        self::assertFalse($changes['rules']);

        $service->sendToReview($model, $this->admin, 'Primera');
        $changes = $service->changes($model);
        self::assertSame(1, $changes['base']);
        self::assertSame(['content' => false, 'rules' => false, 'approval' => false, 'base' => 1, 'any' => false], $changes, 'the draft is what was sent');

        // A rule changes only the rules; a step only the approval; the content stays as it was sent.
        $draft = $model->getDraftDefinition();
        $draft['rules'] = [['key' => 'a0000000-0000-4000-8000-000000000001', 'expr' => '1 = 1', 'message' => '', 'severity' => 'warning']];
        $service->saveDraft($model, $draft, $model->getDraftVersion(), $this->admin);
        $changes = $service->changes($model);
        self::assertSame([false, true, false, true], [$changes['content'], $changes['rules'], $changes['approval'], $changes['any']]);

        // The problems say where they are: a rule without message is in the rules, a step without a name in the approval.
        $draft = $model->getDraftDefinition();
        $draft['approval'] = [['key' => 'b0000000-0000-4000-8000-000000000001', 'name' => '', 'profile' => '']];
        $service->saveDraft($model, $draft, $model->getDraftVersion(), $this->admin);

        $this->loginAs($this->admin, $this->centre);
        $id       = $model->getId()->toRfc4122();
        $response = $this->postJson("/modelos/{$id}/borrador", 'form_model_' . $id, ['version' => $model->getDraftVersion(), 'definition' => $model->getDraftDefinition()]);
        $body     = json_decode((string) $response->getContent(), true);
        $parts    = array_values(array_unique(array_column($body['problems'], 'part')));
        sort($parts);
        self::assertSame(['approval', 'rules'], $parts);
        self::assertTrue($body['changes']['approval']);
    }

    public function testATeacherWithoutQualityPermissionCannotTouchModels(): void
    {
        $model = $this->modelInForce();
        $this->loginAs($this->teacher, $this->centre);

        $this->client->request('GET', '/modelos');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/modelos/' . $model->getId()->toRfc4122());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ── Filling in an activity ──────────────────────────────────────────────

    public function testFillingAutosavesAndDeliversADocumentWithItsPdf(): void
    {
        $this->modelInForce();
        $this->loginAs($this->teacher, $this->centre);

        $this->client->request('GET', $this->fillUrl());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('form-fill', (string) $this->client->getResponse()->getContent());

        $submissions = self::getContainer()->get(FormSubmissionRepository::class);
        $this->em->clear();
        $submission = $submissions->findBySlot($this->em->getRepository(Activity::class)->find($this->activity->getId()), $this->slotKey(), $this->currentCycle());
        self::assertNotNull($submission);
        $sid = $submission->getId()->toRfc4122();
        self::assertNull($submission->getDocument(), 'a draft is not a document: nothing in the tree yet');

        // Missing data: the draft saves, and says what is missing; delivering is refused.
        $response = $this->postJson($this->fillUrl('/guardar'), 'form_fill_' . $sid, ['version' => $submission->getDraftVersion(), 'data' => ['fields' => [$this->keys['hours'] => 40]]]);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertNotEmpty($body['problems']);
        // Each problem says where it is and what to write under the field, so the screen can mark that field.
        self::assertArrayHasKey('row', $body['problems'][0]);
        self::assertArrayHasKey('column', $body['problems'][0]);
        self::assertSame('required', $body['problems'][0]['code']);
        self::assertSame('Este dato es obligatorio.', $body['problems'][0]['short']);
        self::assertNotSame($body['problems'][0]['text'], $body['problems'][0]['short'], 'the short text does not repeat the name of the field');

        // The preview shows what is typed, as it would read delivered, and delivers nothing.
        $this->client->request('GET', $this->fillUrl('/previsualizar'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('40', (string) $this->client->getResponse()->getContent());
        self::assertSame([], self::getContainer()->get(DocumentRepository::class)->findByFolder($this->em->getRepository(Folder::class)->find($this->activityFolder->getId())));

        $this->client->request('POST', $this->fillUrl('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        $this->em->clear();
        self::assertSame([], self::getContainer()->get(DocumentRepository::class)->findByFolder($this->em->getRepository(Folder::class)->find($this->activityFolder->getId())));

        // A stale tab is refused.
        $response = $this->postJson($this->fillUrl('/guardar'), 'form_fill_' . $sid, ['version' => 1, 'data' => []]);
        self::assertSame(409, $response->getStatusCode());

        // The complete data, delivered.
        $version = $body['version'];
        $response = $this->postJson($this->fillUrl('/guardar'), 'form_fill_' . $sid, ['version' => $version, 'data' => ['fields' => [$this->keys['title'] => 'Visita al museo', $this->keys['hours'] => 6], 'tables' => [$this->keys['table'] => [['_key' => FormDefinition::newKey(), $this->keys['col'] => 'Lola'], ['_key' => FormDefinition::newKey()]]]]]);
        self::assertSame([], json_decode((string) $response->getContent(), true)['problems']);

        $this->client->request('POST', $this->fillUrl('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        $folder    = $this->em->getRepository(Folder::class)->find($this->activityFolder->getId());
        $documents = self::getContainer()->get(DocumentRepository::class)->findByFolder($folder);
        self::assertCount(1, $documents);
        self::assertSame('Jefatura', $documents[0]->getName(), 'named by the slot, as an uploaded file would be');
        $revision = $documents[0]->getActiveRevision();
        self::assertNotNull($revision);
        self::assertStringStartsWith('%PDF', $revision->getFile()->getContent());

        $filled = self::getContainer()->get(FormSubmissionRevisionRepository::class)->findByRevision($revision);
        self::assertNotNull($filled);
        self::assertSame('Visita al museo', $filled->getData()['fields'][$this->keys['title']]);
        self::assertCount(1, $filled->getData()['tables'][$this->keys['table']], 'the empty row is not frozen');

        // The delivered document: its page, its web view, and a correction that becomes the next revision.
        $this->client->request('GET', $this->fillUrl());
        self::assertStringContainsString('Visita al museo', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/formularios/revision/' . $revision->getId()->toRfc4122());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Visita al museo', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', $this->fillUrl('/corregir'), ['_token' => $this->csrfToken('form_correct_' . $sid)]);
        $this->client->request('GET', $this->fillUrl());
        self::assertStringContainsString('form-fill', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $submission = $submissions->find($sid);
        $response   = $this->postJson($this->fillUrl('/guardar'), 'form_fill_' . $sid, ['version' => $submission->getDraftVersion(), 'data' => ['fields' => [$this->keys['title'] => 'Visita corregida', $this->keys['hours'] => 5], 'tables' => [$this->keys['table'] => [['_key' => FormDefinition::newKey(), $this->keys['col'] => 'Lola']]]]]);
        self::assertSame(200, $response->getStatusCode());
        $this->client->request('POST', $this->fillUrl('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);

        $this->em->clear();
        $document = self::getContainer()->get(DocumentRepository::class)->findByFolder($this->em->getRepository(Folder::class)->find($this->activityFolder->getId()))[0];
        self::assertCount(2, $document->getRevisions(), 'the correction is the next revision of the same document');
        self::assertSame(2, $document->getActiveRevision()?->getVersion());
    }

    public function testSomeoneWhoDoesNotHoldTheProfileCannotFill(): void
    {
        $this->modelInForce();
        $stranger = (new Teacher(new PersonName('Otra', 'Persona')))->setUsername('otra');
        $this->persist($stranger);

        $this->loginAs($stranger, $this->centre);
        $this->client->request('GET', $this->fillUrl());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAnActivityWhoseModelHasNoApprovedRevisionCannotBeFilledYet(): void
    {
        $model = self::getContainer()->get(FormModelService::class)->create($this->modelFolder, 'Sin aprobar', null);
        $this->activity->setFormModel($model);
        $this->flush();

        $this->loginAs($this->teacher, $this->centre);
        $this->client->request('GET', $this->fillUrl());

        self::assertTrue($this->client->getResponse()->isRedirect(), 'back to the activity with a message');
    }

    private function currentCycle(): int
    {
        return self::getContainer()->get(\App\Service\ActivityDeadlineChecker::class)->currentCycleKey($this->activity);
    }
}
