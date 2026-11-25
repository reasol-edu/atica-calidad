<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Repository\FormApprovalStepRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A step of an approval chain limited to the element of the document (fase 0c): the head of the department of each subject decides
 * the step, not every head of department; a step the one who delivers would decide by their profile is approved at once, never by
 * managing the folder; and an element with no subprofile of the step's profile leaves the step to anyone with it.
 */
final class FormChainElementTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private Folder $folder;
    private SpecificProfile $head;
    private SpecificProfile $studies;
    private Activity $activity;
    private ListItem $informatica;
    private ListItem $programacion;
    private Teacher $claudia;
    private Teacher $pablo;
    private Teacher $elena;
    private Teacher $quality;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section      = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $modelFolder  = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->folder = (new Folder())->setDocumentSection($section)->setName('Programaciones de los módulos');

        $departamento      = (new ListItem())->setEducationalCentre($this->centre)->setName('Departamento');
        $this->informatica = (new ListItem())->setEducationalCentre($this->centre)->setName('Informática')->setParent($departamento);
        $matematicas       = (new ListItem())->setEducationalCentre($this->centre)->setName('Matemáticas')->setParent($departamento);
        $subjects          = (new ListItem())->setEducationalCentre($this->centre)->setName('Materia');
        $this->head        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefe/a de Departamento')->setListItem($departamento);
        $this->studies     = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefe/a de Estudios');
        $qualityProfile    = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Calidad');

        $this->programacion = (new ListItem())->setEducationalCentre($this->centre)->setName('Programación')->setParent($subjects);
        $this->programacion->setAssociation($this->head, $this->informatica);
        $algebra = (new ListItem())->setEducationalCentre($this->centre)->setName('Álgebra')->setParent($subjects);
        $algebra->setAssociation($this->head, $matematicas);

        $this->folder->addUploadProfile($this->head);
        $this->folder->addResponsibleProfile($qualityProfile);
        $category       = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $this->activity = (new Activity())->setCategory($category)->setTitle('Entrega de programaciones')->setStart(1, 9)->setEnd(30, 6)
            ->setFolder($this->folder)->setListItem($subjects);

        $make          = static fn (string $first, string $user): Teacher => (new Teacher(new PersonName($first, 'Apellido')))->setUsername($user);
        $this->claudia = $make('Claudia', 'claudia');
        $this->pablo   = $make('Pablo', 'pablo');
        $this->elena   = $make('Elena', 'elena');
        $this->quality = $make('Laura', 'calidad');

        $this->persist(
            $this->centre, $section, $modelFolder, $this->folder, $departamento, $this->informatica, $matematicas, $subjects,
            $this->head, $this->studies, $qualityProfile, $this->programacion, $algebra, $category, $this->activity,
            $this->claudia, $this->pablo, $this->elena, $this->quality,
            new SpecificProfileAssignment($this->head, $this->informatica, $this->claudia),
            new SpecificProfileAssignment($this->head, $matematicas, $this->pablo),
            new SpecificProfileAssignment($this->studies, null, $this->elena),
            new SpecificProfileAssignment($qualityProfile, null, $this->quality),
        );
        $admin = (new Teacher(new PersonName('Ada', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->persist($admin);
        $service = self::getContainer()->get(FormModelService::class);
        $model   = $service->create($modelFolder, 'Programación didáctica del módulo', 'PD');
        $service->saveDraft($model, $this->definition(), $model->getDraftVersion(), $admin);
        $service->sendToReview($model, $admin, null);
        $this->activity->setFormModel($model);
        $this->flush();
    }

    /** @return array<string, mixed> one field and the chain «Jefatura del departamento» (limited to the element) → «Jefatura de estudios» */
    private function definition(): array
    {
        $this->keys = ['title' => FormDefinition::newKey(), 'section' => FormDefinition::newKey()];

        return ['version' => 1, 'sections' => [[
            'key' => $this->keys['section'], 'title' => 'Datos', 'numbered' => true, 'help' => '', 'children' => [], 'blocks' => [
                ['key' => $this->keys['title'], 'type' => 'field', 'fieldType' => 'short', 'label' => 'Objetivos', 'required' => true],
            ],
        ]], 'approval' => [
            ['name' => 'Jefatura del departamento', 'profile' => $this->head->getId()->toRfc4122(), 'scope' => 'element'],
            ['name' => 'Jefatura de estudios', 'profile' => $this->studies->getId()->toRfc4122(), 'scope' => 'element'],
        ]];
    }

    private function slotKey(): string
    {
        return implode(':', [$this->head->getId()->toRfc4122(), $this->informatica->getId()->toRfc4122(), $this->programacion->getId()->toRfc4122(), '']);
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

    /** Opens the form of «Programación» as $teacher, fills it, delivers it and returns the id of the delivered revision. */
    private function deliver(Teacher $teacher): string
    {
        $this->loginAs($teacher, $this->centre);
        $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $cycle      = self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($this->activity, $this->programacion);
        $submission = self::getContainer()->get(FormSubmissionRepository::class)->findBySlot($this->em->getRepository(Activity::class)->find($this->activity->getId()), $this->slotKey(), $cycle);
        self::assertNotNull($submission);
        $sid = $submission->getId()->toRfc4122();

        $this->client->request('POST', $this->url('/guardar'), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken('form_fill_' . $sid)], json_encode(['version' => $submission->getDraftVersion(), 'data' => ['fields' => [$this->keys['title'] => 'Objetivos del módulo']]], JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', $this->url('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        $document = self::getContainer()->get(DocumentRepository::class)->findByFolder($this->em->getRepository(Folder::class)->find($this->folder->getId()))[0];

        return $document->getRevisions()->last()->getId()->toRfc4122();
    }

    private function decide(Teacher $teacher, string $revisionId, string $decision = 'approve'): Response
    {
        $this->loginAs($teacher, $this->centre);
        $this->client->request('GET', "/formularios/revision/{$revisionId}");
        $this->client->request('POST', "/formularios/revision/{$revisionId}/decidir", ['_token' => $this->csrfToken('form_review_' . $revisionId), 'decision' => $decision, 'note' => '']);

        return $this->client->getResponse();
    }

    /** @return list<string> the states of the steps of a revision, in order */
    private function states(string $revisionId): array
    {
        $this->em->clear();
        $steps = self::getContainer()->get(FormApprovalStepRepository::class)->findByRevision($this->em->getRepository(DocumentRevision::class)->find($revisionId));

        return array_map(static fn ($s): string => $s->getState()->value, $steps);
    }

    public function testOnlyTheHeadOfTheDepartmentOfTheElementDecidesTheStep(): void
    {
        // Delivered by the quality manager (who manages the folder): nothing is approved on their behalf.
        $rev = $this->deliver($this->quality);
        self::assertSame(['pending', 'waiting'], $this->states($rev));

        // Pablo heads another department: the step is not in his queue and he cannot decide it.
        $this->loginAs($this->pablo, $this->centre);
        $this->client->request('GET', '/revisiones');
        self::assertStringNotContainsString('Paso «Jefatura del departamento»', (string) $this->client->getResponse()->getContent());
        self::assertSame(403, $this->decide($this->pablo, $rev)->getStatusCode());
        self::assertSame(['pending', 'waiting'], $this->states($rev));

        // Claudia heads Informática, the department of «Programación».
        $this->loginAs($this->claudia, $this->centre);
        $this->client->request('GET', '/revisiones');
        self::assertStringContainsString('Paso «Jefatura del departamento»', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->decide($this->claudia, $rev)->isRedirect());
        self::assertSame(['approved', 'pending'], $this->states($rev));

        // «Jefe/a de Estudios» has no list: limiting it changes nothing, Elena decides it as always.
        self::assertTrue($this->decide($this->elena, $rev)->isRedirect());
        self::assertSame(['approved', 'approved'], $this->states($rev));
    }

    public function testAStepWhoeverDeliversWouldDecideIsApprovedAtOnceWithANote(): void
    {
        $rev = $this->deliver($this->claudia);

        self::assertSame(['approved', 'pending'], $this->states($rev), 'her own step, approved by delivering; the next waits for its people');
        $this->em->clear();
        $steps = self::getContainer()->get(FormApprovalStepRepository::class)->findByRevision($this->em->getRepository(DocumentRevision::class)->find($rev));
        self::assertSame('claudia', $steps[0]->getDecidedBy()?->getUsername());
        self::assertSame('Entregado y aprobado por la misma persona.', $steps[0]->getNote());
        self::assertTrue($this->em->getRepository(DocumentRevision::class)->find($rev)?->isPendingReview(), 'one step is not the revision');
    }

    public function testTheSubprofileThatDeliversStillLimitsTheStepWhenTheElementLosesItsAssociation(): void
    {
        $rev = $this->deliver($this->quality);

        $this->em->clear();
        $programacion = $this->em->getRepository(ListItem::class)->find($this->programacion->getId());
        self::assertNotNull($programacion);
        $programacion->setAssociation(null);
        $this->em->flush();

        // The slot is still delivered by «Jefe/a de Departamento Informática»: that is who decides, not any head.
        self::assertSame(403, $this->decide($this->pablo, $rev)->getStatusCode());
        self::assertTrue($this->decide($this->claudia, $rev)->isRedirect());
        self::assertSame(['approved', 'pending'], $this->states($rev));
    }

    public function testAStepWithNobodyToBeLimitedToIsOpenToTheProfileAndSaysSo(): void
    {
        $rev = $this->deliver($this->quality);

        // A step of another list profile that neither the element nor the slot belongs to: open to anyone with it.
        $this->em->clear();
        $departamento = $this->em->getRepository(ListItem::class)->findOneBy(['name' => 'Departamento']);
        $matematicas  = $this->em->getRepository(ListItem::class)->findOneBy(['name' => 'Matemáticas']);
        $centre       = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        $pablo        = $this->em->getRepository(Teacher::class)->find($this->pablo->getId());
        self::assertNotNull($departamento);
        self::assertNotNull($matematicas);
        self::assertNotNull($centre);
        self::assertNotNull($pablo);
        $coordination = (new SpecificProfile())->setEducationalCentre($centre)->setName('Coordinación de área')->setListItem($departamento);
        $filled       = self::getContainer()->get(\App\Repository\FormSubmissionRevisionRepository::class)->findByRevisionId($rev);
        self::assertNotNull($filled);
        $step = new \App\Entity\FormApprovalStep($filled, 9, 'Coordinación', $coordination, \App\Entity\FormApprovalStepState::Pending, true);
        $this->persist($coordination, new SpecificProfileAssignment($coordination, $matematicas, $pablo), $step);

        $chain = self::getContainer()->get(\App\Service\Form\FormApprovalChain::class);
        self::assertTrue($chain->isLimitedWithoutElement($step));
        self::assertTrue($chain->holdsStepProfile($pablo, $step), 'anyone with the profile');

        $this->loginAs($this->pablo, $this->centre);
        $this->client->request('GET', "/formularios/revision/{$rev}");
        self::assertStringContainsString('puede decidir cualquiera con ese perfil', (string) $this->client->getResponse()->getContent());
    }
}
