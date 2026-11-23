<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormAttachment;
use App\Entity\FormSubmission;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\DocumentFileRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Service\DocumentFileGarbageCollector;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A field «Adjuntos»: uploading files to a draft, what the data may point at, the delivered PDF (annexes listed, pictures, the
 * originals inside), the ZIP with the annexes, who may download a file, and that the files are not swept as orphans.
 */
final class FormAttachmentTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private Folder $activityFolder;
    private SpecificProfile $profile;
    private Activity $activity;
    private Teacher $admin;
    private Teacher $teacher;
    private Teacher $outsider;
    private string $field;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre         = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section              = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $modelFolder          = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->activityFolder = (new Folder())->setDocumentSection($section)->setName('Entregas');
        $this->profile        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura');
        $this->activityFolder->addUploadProfile($this->profile);
        $category       = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $this->activity = (new Activity())->setCategory($category)->setTitle('Acta')->setStart(1, 9)->setEnd(30, 6)->setFolder($this->activityFolder);
        $this->admin    = (new Teacher(new PersonName('Ana', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->teacher  = (new Teacher(new PersonName('Tomás', 'Docente')))->setUsername('docente');
        $this->outsider = (new Teacher(new PersonName('Olga', 'Otra')))->setUsername('otra');
        $this->persist(
            $this->centre, $section, $modelFolder, $this->activityFolder, $this->profile, $category, $this->activity,
            $this->admin, $this->teacher, $this->outsider, new SpecificProfileAssignment($this->profile, null, $this->teacher),
        );

        $this->field = FormDefinition::newKey();
        $service     = self::getContainer()->get(FormModelService::class);
        $model       = $service->create($modelFolder, 'Acta', 'AC1');
        $service->saveDraft($model, ['version' => 1, 'sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Evidencias', 'numbered' => true, 'help' => '', 'children' => [],
            'blocks' => [['key' => $this->field, 'type' => 'field', 'fieldType' => 'attachment', 'label' => 'Evidencias', 'required' => true, 'maxFiles' => 2, 'printPages' => true]],
        ]]], $model->getDraftVersion(), $this->admin);
        $service->sendToReview($model, $this->admin, null);
        $this->activity->setFormModel($model);
        $this->flush();

        $this->tmp = sys_get_temp_dir() . '/form-attachment-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
        parent::tearDown();
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

    private function fillUrl(string $suffix = ''): string
    {
        return '/actividades/' . $this->activity->getId()->toRfc4122() . '/formulario/' . $this->profile->getId()->toRfc4122() . ':::' . $suffix;
    }

    private function submission(): FormSubmission
    {
        $cycle      = self::getContainer()->get(\App\Service\ActivityDeadlineChecker::class)->currentCycleKey($this->activity);
        $submission = self::getContainer()->get(FormSubmissionRepository::class)->findBySlot($this->em->getRepository(Activity::class)->find($this->activity->getId()), $this->profile->getId()->toRfc4122() . ':::', $cycle);
        self::assertNotNull($submission);

        return $submission;
    }

    /** @return array<string, mixed> the answer of the upload */
    private function upload(string $name, string $content, string $sid, ?string $field = null): array
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $content);
        $this->client->request('POST', $this->fillUrl('/adjuntos'), ['field' => $field ?? $this->field], ['file' => new UploadedFile($path, $name, null, null, true)], ['HTTP_X_CSRF_TOKEN' => $this->csrfToken('form_fill_' . $sid)]);

        return ['status' => $this->client->getResponse()->getStatusCode()] + (json_decode((string) $this->client->getResponse()->getContent(), true) ?: []);
    }

    /** @param array<string, mixed> $data */
    private function save(string $sid, int $version, array $data): array
    {
        $this->client->request('POST', $this->fillUrl('/guardar'), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken('form_fill_' . $sid)], json_encode(['version' => $version, 'data' => $data], JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(60, 40);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function pdf(): string
    {
        return self::getContainer()->get(\App\Service\PdfRenderer::class)->render('pdf/form_document.html.twig', ['centre' => $this->centre, 'title' => 'Anexo', 'code' => null, 'sections' => [], 'blank' => false], 'Anexo', 'a.pdf')->getContent() ?: '';
    }

    public function testFilesAreUploadedDeliveredWithThePdfAndDownloadedByWhoMay(): void
    {
        $this->loginAs($this->teacher, $this->centre);
        $this->client->request('GET', $this->fillUrl());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('attachUrl', (string) $this->client->getResponse()->getContent());
        $submission = $this->submission();
        $sid        = $submission->getId()->toRfc4122();

        // What is not a picture, a PDF, an office document or text is refused, whatever its name says.
        $bad = $this->upload('virus.pdf', "MZ\x90\x00 not really a pdf", $sid);
        self::assertSame(422, $bad['status']);
        self::assertSame('attachment.type', $bad['error']);
        // Only to a field «Adjuntos».
        self::assertSame('attachment.not_a_field', $this->upload('a.png', self::png(), $sid, FormDefinition::newKey())['error']);

        $photo = $this->upload('foto.png', self::png(), $sid);
        $act   = $this->upload('acta.pdf', $this->pdf(), $sid);
        self::assertSame([200, 200], [$photo['status'], $act['status']]);
        self::assertSame(['foto.png', 'image/png'], [$photo['name'], $photo['mime']]);
        self::assertStringContainsString('/formularios/adjunto/' . $photo['id'], $photo['url']);

        // The data may only point at this document's own files, as they are (a forged name or size, another file, are not kept).
        $foreign = new FormAttachment($this->foreignSubmission(), self::getContainer()->get(\App\Service\DocumentCreationService::class)->storeFile('x', 'text/plain', 'x.txt'), 'ajeno.txt', null, new \DateTimeImmutable());
        $this->persist($foreign);
        $body = $this->save($sid, $submission->getDraftVersion(), ['fields' => [$this->field => [
            ['id' => $photo['id'], 'name' => 'renombrado.exe', 'size' => 1, 'mime' => 'text/html'],
            ['id' => $foreign->getId()->toRfc4122(), 'name' => 'ajeno.txt', 'size' => 1, 'mime' => 'text/plain'],
            ['id' => $act['id'], 'name' => 'acta.pdf', 'size' => 1, 'mime' => 'application/pdf'],
        ]]]);
        self::assertSame([], $body['problems']);
        $this->em->clear();
        $saved = $this->submission()->getDraftData()['fields'][$this->field];
        self::assertSame([$photo['id'], $act['id']], array_column($saved, 'id'));
        self::assertSame(['foto.png', 'image/png'], [$saved[0]['name'], $saved[0]['mime']]);

        // Who fills the slot downloads them; somebody else, not even knowing the id, cannot.
        $this->client->request('GET', '/formularios/adjunto/' . $photo['id']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));

        // Delivered: the PDF lists the annexes, draws the picture, carries the originals and the pages of the act at the end.
        $this->client->request('POST', $this->fillUrl('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        $this->em->clear();
        $filled = self::getContainer()->get(FormSubmissionRevisionRepository::class)->findLatest($this->submission());
        self::assertNotNull($filled);
        $pdf = $filled->getRevision()->getFile()->getContent();
        self::assertStringContainsString('/EmbeddedFiles', $pdf, 'the originals are inside the PDF');
        self::assertGreaterThanOrEqual(1, substr_count($pdf, '/Subtype /Image'), 'the picture is drawn');
        self::assertGreaterThanOrEqual(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'the pages of the attached PDF are appended');

        $revisionId = $filled->getRevision()->getId()->toRfc4122();
        $this->client->request('GET', '/formularios/revision/' . $revisionId);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Anexo 1', $html);
        self::assertStringContainsString('con-anexos.zip', $html);

        $this->client->request('GET', '/formularios/revision/' . $revisionId . '/con-anexos.zip');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $zipPath = $this->tmp . '/out.zip';
        file_put_contents($zipPath, (string) $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        self::assertSame(['Jefatura.pdf', 'anexos/01-foto.png', 'anexos/02-acta.pdf'], $names, 'the document is named after its slot');

        // Word and LibreOffice: the picture is drawn under its field (the PDF is only referred to), well-formed and linked.
        foreach (['docx' => ['word/document.xml', 'word/media/anexo-1.png', 'word/_rels/document.xml.rels'], 'odt' => ['content.xml', 'Pictures/anexo-1.png', 'META-INF/manifest.xml']] as $format => [$main, $picture, $links]) {
            $this->client->request('GET', '/formularios/revision/' . $revisionId . '/exportar.' . $format);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $path = $this->tmp . '/out.' . $format;
            file_put_contents($path, (string) $this->client->getResponse()->getContent());
            $office = new \ZipArchive();
            self::assertTrue($office->open($path));
            self::assertNotFalse($office->getFromName($picture), $format . ': the picture is in the package');
            self::assertFalse($office->getFromName(str_replace('anexo-1.png', 'anexo-2.pdf', $picture)), $format . ': a PDF is not drawn');
            $xml = (string) $office->getFromName($main);
            self::assertTrue((new \DOMDocument())->loadXML($xml), $format . ': well-formed');
            self::assertStringContainsString('Anexo 1: foto.png', $xml);
            self::assertStringContainsString($format === 'docx' ? 'r:embed="rIdFig1"' : 'xlink:href="Pictures/anexo-1.png"', $xml);
            self::assertStringContainsString($format === 'docx' ? 'Target="media/anexo-1.png"' : 'manifest:full-path="Pictures/anexo-1.png"', (string) $office->getFromName($links));
            $office->close();
        }

        // A file only in the new draft (never delivered) is the slot's alone; what was delivered, whoever sees the folder sees, like
        // the document's PDF.
        $this->client->request('POST', $this->fillUrl('/corregir'), ['_token' => $this->csrfToken('form_correct_' . $sid)]);
        $draftOnly = $this->upload('borrador.png', self::png() . 'b', $sid);
        self::assertSame(200, $draftOnly['status']);
        $this->loginAs($this->outsider, $this->centre);
        $this->client->request('GET', '/formularios/adjunto/' . $draftOnly['id']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'not delivered: not for the folder');
        $this->client->request('GET', '/formularios/adjunto/' . $photo['id']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'delivered: whoever sees the folder');

        // The files are in use: neither the collector nor the sweep of orphans deletes them.
        $file = $this->em->getRepository(FormAttachment::class)->find(\Symfony\Component\Uid\Uuid::fromString($photo['id']))?->getFile();
        self::assertNotNull($file);
        self::getContainer()->get(DocumentFileGarbageCollector::class)->deleteIfOrphaned($file);
        self::getContainer()->get(DocumentFileRepository::class)->deleteOrphans();
        $this->em->clear();
        self::assertNotNull(self::getContainer()->get(DocumentFileRepository::class)->findById($file->getId()->toRfc4122()));
    }

    public function testAFieldWithRoomForTwoKeepsTwo(): void
    {
        $this->loginAs($this->teacher, $this->centre);
        $this->client->request('GET', $this->fillUrl());
        $submission = $this->submission();
        $sid        = $submission->getId()->toRfc4122();
        $ids        = [];
        foreach (['a.png', 'b.png', 'c.png'] as $name) {
            $ids[] = ['id' => $this->upload($name, self::png() . $name, $sid)['id'], 'name' => $name];
        }
        $this->save($sid, $submission->getDraftVersion(), ['fields' => [$this->field => $ids]]);
        $this->em->clear();
        self::assertCount(2, $this->submission()->getDraftData()['fields'][$this->field]);
    }

    /** A draft of another slot (another activity), whose files this one must not reach. */
    private function foreignSubmission(): FormSubmission
    {
        $mine  = $this->em->getRepository(Activity::class)->find($this->activity->getId());
        self::assertNotNull($mine);
        $folder = (new Folder())->setDocumentSection($mine->getFolder()?->getDocumentSection())->setName('Otras entregas');
        $other  = (new Activity())->setCategory($mine->getCategory())->setTitle('Otra')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder)->setFormModel($mine->getFormModel());
        $this->persist($folder, $other);
        $sub = new FormSubmission($other, 'otra:::', 2026, $this->submission()->getModelRevision(), []);
        $this->persist($sub);

        return $sub;
    }
}
