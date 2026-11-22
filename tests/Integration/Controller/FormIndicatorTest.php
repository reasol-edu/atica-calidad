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
 * Indicators fed from delivered documents: the index of values, aggregations, the breakdown by slot, and what is never overwritten.
 */
final class FormIndicatorTest extends ControllerTestCase
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

    private function indicatorWithSource(string $aggregation, ?string $op = null, ?string $value = null, string $groupBy = ''): \App\Entity\IndicatorFormSource
    {
        $year = (new \App\Entity\AcademicYear())->setName('2026-2027')->setEducationalCentre($this->em->getRepository(EducationalCentre::class)->find($this->centre->getId()));
        $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId())->setActiveAcademicYear($year);
        $this->persist($year);
        $indicators = self::getContainer()->get(\App\Service\IndicatorService::class);
        $centre     = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        $calendar   = $indicators->createCalendar($centre, $year, 'Curso', null);
        $indicators->saveCalendar($calendar, 'Curso', [['id' => null, 'name' => 'Final', 'start' => new \DateTimeImmutable('2026-09-01'), 'end' => new \DateTimeImmutable('2027-06-30')]]);
        $teacher   = $this->em->getRepository(Teacher::class)->find($this->author->getId());
        $indicator = $indicators->saveIndicator($centre, null, $year, ['name' => 'Horas', 'description' => null, 'section' => null, 'unit' => 'h', 'higherIsBetter' => true, 'teacher' => $teacher, 'profile' => null, 'active' => true, 'calendar' => $calendar, 'target' => 10.0, 'alertThreshold' => 8.0]);
        $activity  = $this->em->getRepository(Activity::class)->find($this->activity->getId());
        $source    = (new \App\Entity\IndicatorFormSource($indicator, $activity, $this->keys['hours']))->configure($activity, $this->keys['hours'], $aggregation, $op, $value, null, $groupBy);
        $this->persist($source);

        return $source;
    }

    public function testDeliveringIndexesTheValues(): void
    {
        $this->deliver('1º A', 5);
        $this->em->clear();
        $rows = self::getContainer()->get(\App\Repository\FormValueRepository::class)->findAll();
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->getFieldKey()] = $row;
        }
        self::assertSame(5.0, $byKey[$this->keys['hours']]->getNum());
        self::assertSame('1º A', $byKey[$this->keys['group']]->getText());
    }

    public function testASumIsComputedWithItsSampleAndBreakdownAndNeverOverwritesAManualValue(): void
    {
        $this->deliver('1º A', 5);
        $source = $this->indicatorWithSource('sum', groupBy: 'slot');
        $source = $this->em->getRepository(\App\Entity\IndicatorFormSource::class)->find($source->getId());
        $calc   = self::getContainer()->get(\App\Service\Form\FormIndicatorCalculator::class);

        self::assertSame(1, $calc->recalculate($source));
        $this->em->clear();
        $measurement = $this->em->getRepository(\App\Entity\Measurement::class)->findAll()[0];
        self::assertSame(5.0, $measurement->getValue());
        self::assertTrue($measurement->isComputed());
        self::assertSame(1, $measurement->getSampleCount());
        self::assertSame(1, $measurement->getExpectedCount());
        self::assertCount(1, $measurement->getBreakdown());
        self::assertSame($this->slotKey(), $measurement->getBreakdown()->first()->getGroupKey());
        self::assertSame(5.0, $measurement->getBreakdown()->first()->getValue());

        // A value that was handed over to a person is not touched again.
        $measurement->makeManual();
        $this->em->flush();
        $source = $this->em->getRepository(\App\Entity\IndicatorFormSource::class)->findAll()[0];
        self::assertSame(0, $calc->recalculate($source));
    }

    public function testAPercentageByConditionAndNothingWithoutApprovedDeliveries(): void
    {
        $source = $this->indicatorWithSource('percent_if', 'gt', '3');
        $source = $this->em->getRepository(\App\Entity\IndicatorFormSource::class)->find($source->getId());
        $calc   = self::getContainer()->get(\App\Service\Form\FormIndicatorCalculator::class);
        self::assertSame(0, $calc->recalculate($source), 'nothing delivered yet: no measurement');

        $this->deliver('1º A', 5);
        $source = $this->em->getRepository(\App\Entity\IndicatorFormSource::class)->findAll()[0];
        self::assertSame(1, $calc->recalculate($source));
        $this->em->clear();
        self::assertSame(100.0, $this->em->getRepository(\App\Entity\Measurement::class)->findAll()[0]->getValue());
    }

    public function testAnApprovedDeliveryRecalculatesItsIndicatorsAtOnce(): void
    {
        $this->indicatorWithSource('sum');
        $this->deliver('1º A', 7);
        $this->em->clear();
        $measurements = $this->em->getRepository(\App\Entity\Measurement::class)->findAll();
        self::assertCount(1, $measurements);
        self::assertSame(7.0, $measurements[0]->getValue());
    }

    public function testAComputedValueCanBeHandedOverToAPersonAndIsThenNeverRecalculated(): void
    {
        $this->indicatorWithSource('sum');
        $this->deliver('1º A', 7);
        $this->em->clear();
        $measurement = $this->em->getRepository(\App\Entity\Measurement::class)->findAll()[0];
        self::assertTrue($measurement->isComputed());
        $mid = $measurement->getId()->toRfc4122();

        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/mejora/indicadores/' . $measurement->getIndicator()->getId()->toRfc4122());
        self::assertStringContainsString('data-testid="make-manual"', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', "/mejora/indicadores/mediciones/{$mid}/manual", ['_token' => $this->csrfToken('quality_review_' . $mid)]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        $measurement = $this->em->getRepository(\App\Entity\Measurement::class)->find($measurement->getId());
        self::assertFalse($measurement->isComputed());
        self::assertSame(7.0, $measurement->getValue());
        $source = $this->em->getRepository(\App\Entity\IndicatorFormSource::class)->findAll()[0];
        self::assertSame(0, self::getContainer()->get(\App\Service\Form\FormIndicatorCalculator::class)->recalculate($source));
    }

    public function testTheExplorerListsApprovedValuesAndExportsThem(): void
    {
        $this->deliver('1º A', 5, 'a;b');
        $cycle = self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($this->em->getRepository(Activity::class)->find($this->activity->getId()));
        $base  = '/centro/' . $this->centre->getId()->toRfc4122() . '/informes/datos-de-modelos';
        $query = '?actividad=' . $this->activity->getId()->toRfc4122() . '&curso=' . $cycle;

        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', $base . $query);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="form-data-table"', $body);
        self::assertStringContainsString('Horas', $body);
        self::assertStringContainsString('Observaciones', $body);

        $this->client->request('GET', $base . '.csv' . $query);
        self::assertResponseIsSuccessful();
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Campo;Valor', $csv);
        self::assertStringContainsString('Horas;5', $csv);

        $this->client->request('GET', $base . '.xlsx' . $query);
        self::assertResponseIsSuccessful();

        // Whoever cannot see the reports cannot see the data either.
        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', $base . $query);
        self::assertResponseStatusCodeSame(403);
    }

    public function testADeliveredDocumentExportsToWordAndOpenDocument(): void
    {
        $this->deliver('1º A', 5);
        $this->em->clear();
        $revisionId = $this->em->getRepository(\App\Entity\FormSubmissionRevision::class)->findAll()[0]->getRevision()->getId()->toRfc4122();

        $this->loginAs($this->admin, $this->centre);
        foreach (['docx' => 'word/document.xml', 'odt' => 'content.xml'] as $format => $entry) {
            $this->client->request('GET', "/formularios/revision/{$revisionId}/exportar.{$format}");
            self::assertResponseIsSuccessful();
            $path = tempnam(sys_get_temp_dir(), 'rev');
            file_put_contents($path, $this->client->getResponse()->getContent());
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $xml = (string) $zip->getFromName($entry);
            self::assertStringContainsString('1º A', $xml);
            self::assertStringContainsString('Horas', $xml);
        }
    }

    public function testTheModelEditorOffersToCreateAnIndicatorFromAField(): void
    {
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/modelos/' . $this->model->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="indicator-sources"', $body);
        self::assertStringContainsString('Indicador con «Horas»', $body);
    }

    public function testTheIndicatorFormOffersFieldsAndAGroupBelowTheThresholdOpensItsOwnFinding(): void
    {
        $this->deliver('1º A', 5);
        $source = $this->indicatorWithSource('sum', groupBy: 'slot');
        $this->em->clear();
        $source = $this->em->getRepository(\App\Entity\IndicatorFormSource::class)->find($source->getId());
        self::getContainer()->get(\App\Service\Form\FormIndicatorCalculator::class)->recalculate($source);
        $this->em->clear();
        $measurement = $this->em->getRepository(\App\Entity\Measurement::class)->findAll()[0];
        $indicatorId = $measurement->getIndicator()->getId()->toRfc4122();

        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/mejora/indicadores/nuevo');
        self::assertStringContainsString('data-testid="source-fieldset"', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', "/mejora/indicadores/{$indicatorId}");
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="computed-value"', $body);
        self::assertStringContainsString('Por debajo del umbral', $body);

        $mid = $measurement->getId()->toRfc4122();
        $this->client->request('POST', "/mejora/indicadores/mediciones/{$mid}/grupo/" . rawurlencode($this->slotKey()) . '/hallazgo', ['_token' => $this->csrfToken('quality_review_' . $mid)]);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('/mejora/fichas/', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
