<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Tests\Integration\ControllerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Models in and out as files: JSON library, DOCX/ODT export and import, and copying a model.
 */
final class FormLibraryTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private Folder $folder;
    private Teacher $admin;
    private FormModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section      = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->folder = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->admin  = (new Teacher(new PersonName('Ada', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->persist($this->centre, $section, $this->folder, $this->admin);

        $service     = self::getContainer()->get(FormModelService::class);
        $this->model = $service->create($this->folder, 'Ficha de visita', 'MD1');
        $definition  = ['version' => 1, 'sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Datos', 'numbered' => true, 'help' => '', 'children' => [],
            'blocks' => [
                ['key' => FormDefinition::newKey(), 'type' => 'field', 'name' => 'grupo', 'fieldType' => 'short', 'label' => 'Grupo'],
                ['key' => FormDefinition::newKey(), 'type' => 'text', 'mode' => 'fixed', 'label' => '', 'html' => '<p>Texto fijo</p>', 'required' => false, 'help' => ''],
                ['key' => FormDefinition::newKey(), 'type' => 'table', 'name' => 'alumnos', 'label' => 'Alumnado', 'mode' => 'free', 'rowHeader' => '', 'rows' => [], 'minRows' => 0, 'maxRows' => 5, 'help' => '', 'columns' => [
                    ['key' => FormDefinition::newKey(), 'name' => 'nombre', 'label' => 'Nombre', 'fieldType' => 'short', 'required' => false],
                    ['key' => FormDefinition::newKey(), 'name' => 'edad', 'label' => 'Edad', 'fieldType' => 'number', 'required' => false],
                ]],
            ],
        ]]];
        $model = $this->em->getRepository(FormModel::class)->find($this->model->getId());
        $service->saveDraft($model, $definition, $model->getDraftVersion(), $this->em->getRepository(Teacher::class)->find($this->admin->getId()));
        $this->em->flush();
        $this->loginAs($this->admin, $this->centre);
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

    private function upload(string $content, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '-' . $name;
        file_put_contents($path, $content);

        return ['file' => new UploadedFile($path, $name, null, null, true)];
    }

    private function modelCount(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(FormModel::class)->findAll());
    }

    public function testJsonExportAndImportRoundTrip(): void
    {
        $id = $this->model->getId()->toRfc4122();
        $this->client->request('GET', "/modelos/{$id}/exportar.json");
        self::assertResponseIsSuccessful();
        $json = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"format": "atica-modelo"', $json);
        self::assertStringNotContainsString('"approval"', $json);

        $this->client->request('GET', '/modelos');
        $this->client->request('POST', '/modelos/importar', ['_token' => $this->csrfToken('form_model_import'), 'folder' => $this->folder->getId()->toRfc4122(), 'name' => 'Importado'], $this->upload($json, 'm.json'));
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertStringContainsString('/modelos/', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(2, $this->modelCount());
        $names = array_map(static fn (FormModel $m): string => $m->getDocument()->getName(), $this->em->getRepository(FormModel::class)->findAll());
        self::assertContains('Importado', $names);
        $imported = array_values(array_filter($this->em->getRepository(FormModel::class)->findAll(), static fn (FormModel $m): bool => $m->getDocument()->getName() === 'Importado'))[0];
        self::assertSame('Datos', $imported->getDraftDefinition()['sections'][0]['title']);
    }

    public function testAnInvalidFileIsRefused(): void
    {
        $this->client->request('GET', '/modelos');
        $this->client->request('POST', '/modelos/importar', ['_token' => $this->csrfToken('form_model_import'), 'folder' => $this->folder->getId()->toRfc4122()], $this->upload('{"format":"otro"}', 'm.json'));
        self::assertResponseRedirects('/modelos');
        $this->client->request('POST', '/modelos/importar', ['_token' => $this->csrfToken('form_model_import'), 'folder' => $this->folder->getId()->toRfc4122()], $this->upload('esto no es un zip', 'm.docx'));
        self::assertResponseRedirects('/modelos');
        self::assertSame(1, $this->modelCount());
    }

    public function testDuplicatingMakesAnIndependentDraft(): void
    {
        $id = $this->model->getId()->toRfc4122();
        $this->client->request('GET', '/modelos');
        $this->client->request('POST', "/modelos/{$id}/duplicar", ['_token' => $this->csrfToken('form_model_duplicate_' . $id)]);
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame(2, $this->modelCount());
        $names = array_map(static fn (FormModel $m): string => $m->getDocument()->getName(), $this->em->getRepository(FormModel::class)->findAll());
        self::assertContains('Copia de Ficha de visita', $names);
    }

    #[DataProvider('formats')]
    public function testOfficeExportIsAValidFileThatImportsBackAsAnOutline(string $format, string $entry): void
    {
        $id = $this->model->getId()->toRfc4122();
        $this->client->request('GET', "/modelos/{$id}/exportar.{$format}");
        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $path = tempnam(sys_get_temp_dir(), 'off');
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $xml = (string) $zip->getFromName($entry);
        $zip->close();
        self::assertStringContainsString('Ficha de visita', $xml);
        self::assertStringContainsString('Alumnado', $xml);
        self::assertNotFalse(simplexml_load_string($xml), 'well-formed XML');

        $this->client->request('GET', '/modelos');
        $this->client->request('POST', '/modelos/importar', ['_token' => $this->csrfToken('form_model_import'), 'folder' => $this->folder->getId()->toRfc4122(), 'name' => 'Desde Office'], $this->upload($content, 'x.' . $format));
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        $found = array_values(array_filter($this->em->getRepository(FormModel::class)->findAll(), static fn (FormModel $m): bool => $m->getDocument()->getName() === 'Desde Office'));
        self::assertCount(1, $found);
        $titles = array_map(static fn (array $s): string => $s['title'], $found[0]->getDraftDefinition()['sections']);
        self::assertContains('Datos', array_map(static fn (string $t): string => preg_replace('/^\d+\.\s*/', '', $t), $titles));
        $types = [];
        foreach (FormDefinition::blocks($found[0]->getDraftDefinition()) as $entry) {
            $types[] = $entry['block']['type'];
        }
        self::assertContains('table', $types);
        self::assertContains('text', $types);
    }

    /** @return iterable<string, array{string, string}> */
    public static function formats(): iterable
    {
        yield 'docx' => ['docx', 'word/document.xml'];
        yield 'odt'  => ['odt', 'content.xml'];
    }
}
