<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\FormUpdatePolicy;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\FormSubmissionRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Service\Form\FormSubmissionService;
use App\Tests\Integration\ControllerTestCase;

/**
 * What the third part of the fillable documents adds: a new revision of a model reaching what was already filled (optional or
 * mandatory update, with what would be lost told beforehand), drafts pre-filled from last year or from another activity, and the
 * drafts of everybody created when the period opens.
 */
final class FormUpdateTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private Folder $modelFolder;
    private Folder $activityFolder;
    private SpecificProfile $authors;
    private Activity $activity;
    private Teacher $author;
    private Teacher $admin;
    private FormModel $model;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre         = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section              = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->modelFolder    = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->activityFolder = (new Folder())->setDocumentSection($section)->setName('Entregas');
        $this->authors        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Profesorado');
        $this->activityFolder->addUploadProfile($this->authors);
        $category       = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $this->activity = (new Activity())->setCategory($category)->setTitle('Salida')->setStart(1, 9)->setEnd(30, 6)->setFolder($this->activityFolder);
        $this->author   = (new Teacher(new PersonName('Ana', 'Apellido')))->setUsername('autora');
        $this->admin    = (new Teacher(new PersonName('Ada', 'Admin')))->setUsername('admin')->setAdmin(true);

        $this->persist(
            $this->centre, $section, $this->modelFolder, $this->activityFolder, $this->authors, $category, $this->activity, $this->author, $this->admin,
            new SpecificProfileAssignment($this->authors, null, $this->author),
        );

        $this->model = self::getContainer()->get(FormModelService::class)->create($this->modelFolder, 'Ficha', 'MD1');
        $this->publish($this->definition(withBlock: true));
        $this->activity->setFormModel($this->model);
        $this->flush();
    }

    /** @return array<string, mixed> a field «grupo» always, a field «horas» and, in the first revision only, «observaciones» */
    private function definition(bool $withBlock, bool $withExtra = false): array
    {
        $this->keys += ['group' => FormDefinition::newKey(), 'hours' => FormDefinition::newKey(), 'notes' => FormDefinition::newKey(), 'extra' => FormDefinition::newKey(), 'section' => FormDefinition::newKey()];
        $blocks = [
            ['key' => $this->keys['group'], 'type' => 'field', 'name' => 'grupo', 'fieldType' => 'short', 'label' => 'Grupo'],
            ['key' => $this->keys['hours'], 'type' => 'field', 'name' => 'horas', 'fieldType' => 'number', 'label' => 'Horas'],
        ];
        if ($withBlock) {
            $blocks[] = ['key' => $this->keys['notes'], 'type' => 'field', 'name' => 'obs', 'fieldType' => 'short', 'label' => 'Observaciones'];
        }
        if ($withExtra) {
            $blocks[] = ['key' => $this->keys['extra'], 'type' => 'field', 'name' => 'extra', 'fieldType' => 'short', 'label' => 'Extra'];
        }

        return ['version' => 1, 'sections' => [['key' => $this->keys['section'], 'title' => 'Datos', 'numbered' => true, 'help' => '', 'children' => [], 'blocks' => $blocks]]];
    }

    /** @param array<string, mixed> $definition */
    private function publish(array $definition, FormUpdatePolicy $policy = FormUpdatePolicy::Optional, ?\DateTimeImmutable $deadline = null): void
    {
        $service = self::getContainer()->get(FormModelService::class);
        // Requests clear the entity manager: work with managed copies.
        $model = $this->em->getRepository(FormModel::class)->find($this->model->getId());
        $admin = $this->em->getRepository(Teacher::class)->find($this->admin->getId());
        $service->saveDraft($model, $definition, $model->getDraftVersion(), $admin);
        $service->sendToReview($model, $admin, null, $policy, $deadline);
        $this->em->flush();
    }

    private function slotKey(): string
    {
        return $this->authors->getId()->toRfc4122() . ':::';
    }

    private function url(string $suffix = ''): string
    {
        return '/actividades/' . $this->activity->getId()->toRfc4122() . '/formulario/' . $this->slotKey() . $suffix;
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

    private function submission(): \App\Entity\FormSubmission
    {
        $this->em->clear();
        $cycle = self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($this->em->getRepository(Activity::class)->find($this->activity->getId()));
        $found = self::getContainer()->get(FormSubmissionRepository::class)->findBySlot($this->em->getRepository(Activity::class)->find($this->activity->getId()), $this->slotKey(), $cycle);
        self::assertNotNull($found);

        return $found;
    }

    /** Opens the form as the author, types the values and delivers it. */
    private function deliver(string $group, int $hours, string $notes = ''): void
    {
        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', $this->url());
        $submission = $this->submission();
        $sid        = $submission->getId()->toRfc4122();
        $fields     = [$this->keys['group'] => $group, $this->keys['hours'] => $hours] + ($notes === '' ? [] : [$this->keys['notes'] => $notes]);
        $this->client->request('POST', $this->url('/guardar'), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken('form_fill_' . $sid)], json_encode(['version' => $submission->getDraftVersion(), 'data' => ['fields' => $fields]], JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', $this->url('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertFalse($this->submission()->hasDraft(), 'delivered');
    }

    private function postUpdate(string $action): void
    {
        $sid = $this->submission()->getId()->toRfc4122();
        $this->client->request('POST', $this->url('/' . $action), ['_token' => $this->csrfToken('form_' . ['actualizar' => 'update', 'desde-cero' => 'scratch', 'reprerrellenar' => 'prefill'][$action] . '_' . $sid)]);
    }

    // ── Update to a newer revision ───────────────────────────────────────────

    public function testAnOptionalUpdateKeepsWhatStillFitsAndSaysWhatWillBeLost(): void
    {
        $this->deliver('1º A', 5, 'Sin novedad');
        $this->publish($this->definition(withBlock: false, withExtra: true));

        $this->client->request('GET', $this->url());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="update-banner"', $body);
        self::assertStringContainsString('Observaciones', $body, 'the field that will not fit any more is named');
        self::assertStringNotContainsString('Grupo,', $body);

        $this->postUpdate('actualizar');
        $submission = $this->submission();
        self::assertTrue($submission->hasDraft());
        self::assertSame(2, $submission->getModelRevision()->getRevision()->getVersion());
        $fields = $submission->getDraftData()['fields'];
        self::assertSame('1º A', $fields[$this->keys['group']], 'what still has a place is kept');
        self::assertArrayNotHasKey($this->keys['notes'], $fields, 'what has none is dropped');

        $this->client->request('GET', $this->url());
        self::assertStringNotContainsString('data-testid="update-banner"', (string) $this->client->getResponse()->getContent(), 'already on the revision in force');
    }

    public function testAMandatoryUpdateBlocksDeliveringTheOldRevisionOnceDue(): void
    {
        $this->deliver('1º A', 5);
        $this->publish($this->definition(withBlock: true, withExtra: true), FormUpdatePolicy::Mandatory, new \DateTimeImmutable('-1 day'));

        // The delivered document is corrected: the draft starts on the revision it was delivered with, and cannot be delivered again as it is.
        $this->client->request('POST', $this->url('/corregir'), ['_token' => $this->csrfToken('form_correct_' . $this->submission()->getId()->toRfc4122())]);
        $sid = $this->submission()->getId()->toRfc4122();
        self::assertSame(1, $this->submission()->getModelRevision()->getRevision()->getVersion());
        $this->client->request('POST', $this->url('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        self::assertTrue($this->submission()->hasDraft(), 'refused: still a draft');

        $this->client->request('GET', $this->url());
        self::assertStringContainsString('de uso obligatorio', (string) $this->client->getResponse()->getContent());

        $this->postUpdate('actualizar');
        $this->client->request('POST', $this->url('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        self::assertFalse($this->submission()->hasDraft(), 'delivered once updated');
    }

    public function testAMandatoryUpdateWithAFutureDateStillAllowsDelivering(): void
    {
        $this->deliver('1º A', 5);
        $this->publish($this->definition(withBlock: true, withExtra: true), FormUpdatePolicy::Mandatory, new \DateTimeImmutable('+30 days'));
        $this->client->request('POST', $this->url('/corregir'), ['_token' => $this->csrfToken('form_correct_' . $this->submission()->getId()->toRfc4122())]);
        $sid = $this->submission()->getId()->toRfc4122();

        $this->client->request('POST', $this->url('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        self::assertFalse($this->submission()->hasDraft());
    }

    public function testThePublisherSeesWhatPublishingTouches(): void
    {
        $this->deliver('1º A', 5);
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/modelos/' . $this->model->getId()->toRfc4122());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="send-impact"', $body);
        self::assertStringContainsString('name="policy"', $body);
    }

    // ── Pre-filling ──────────────────────────────────────────────────────────

    public function testADraftIsPrefilledFromAnotherActivityByTheNameOfTheFields(): void
    {
        $this->deliver('2º B', 7);

        // Another model, with its own keys, shares the internal names «grupo» and «horas».
        $this->em->clear();
        $modelFolder = $this->em->getRepository(Folder::class)->find($this->modelFolder->getId());
        $admin       = $this->em->getRepository(Teacher::class)->find($this->admin->getId());
        $service     = self::getContainer()->get(FormModelService::class);
        $other       = $service->create($modelFolder, 'Otra ficha', null);
        $keys        = ['g' => FormDefinition::newKey(), 'h' => FormDefinition::newKey(), 's' => FormDefinition::newKey()];
        $service->saveDraft($other, ['version' => 1, 'sections' => [['key' => $keys['s'], 'title' => 'Otros', 'numbered' => true, 'help' => '', 'children' => [], 'blocks' => [
            ['key' => $keys['g'], 'type' => 'field', 'name' => 'grupo', 'fieldType' => 'short', 'label' => 'Clase'],
            ['key' => $keys['h'], 'type' => 'field', 'name' => 'horas', 'fieldType' => 'short', 'label' => 'Horas en texto'],
        ]]]], $other->getDraftVersion(), $admin);
        $service->sendToReview($other, $admin, null);

        $first    = $this->em->getRepository(Activity::class)->find($this->activity->getId());
        $folder2  = (new Folder())->setDocumentSection($modelFolder->getDocumentSection())->setName('Entregas 2');
        $folder2->addUploadProfile($this->em->getRepository(SpecificProfile::class)->find($this->authors->getId()));
        $second = (new Activity())->setCategory($first->getCategory())->setTitle('Otra salida')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder2)->setFormModel($other)->setFormPrefillSource($first);
        $this->persist($folder2, $second);

        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', '/actividades/' . $second->getId()->toRfc4122() . '/formulario/' . $this->slotKey());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-testid="prefill-banner"', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $draft = $this->em->getRepository(\App\Entity\FormSubmission::class)->findOneBy(['activity' => $second->getId()]);
        self::assertNotNull($draft);
        self::assertSame('2º B', $draft->getDraftData()['fields'][$keys['g']]);
        self::assertSame('document', $draft->getPrefilledFrom()['kind']);
        self::assertArrayNotHasKey($keys['h'], $draft->getDraftData()['fields'], 'a number does not go into a text field');
    }

    public function testStartingFromScratchDropsThePrefilledValues(): void
    {
        $this->deliver('2º B', 7);
        $this->postUpdate('desde-cero');
        $this->client->request('POST', $this->url('/corregir'), ['_token' => $this->csrfToken('form_correct_' . $this->submission()->getId()->toRfc4122())]);
        $this->postUpdate('desde-cero');
        $submission = $this->submission();
        self::assertNull($submission->getPrefilledFrom());
        self::assertArrayNotHasKey($this->keys['group'], $submission->getDraftData()['fields'] ?? []);
    }

    // ── Drafts for everybody ─────────────────────────────────────────────────

    public function testEverybodysDraftIsCreatedOnceAndOnlyOnce(): void
    {
        $service  = self::getContainer()->get(FormSubmissionService::class);
        $activity = $this->em->getRepository(Activity::class)->find($this->activity->getId());

        $created = $service->createMissingDrafts($activity);
        self::assertGreaterThanOrEqual(1, $created);
        self::assertSame(0, $service->createMissingDrafts($activity), 'a second run creates nothing');
        self::assertTrue($this->submission()->hasDraft());
    }
}
