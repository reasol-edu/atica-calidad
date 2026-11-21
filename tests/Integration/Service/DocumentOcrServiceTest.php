<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\DocumentFile;
use App\Entity\TextIndexStatus;
use App\Repository\DocumentFileRepository;
use App\Repository\DocumentFileTextRepository;
use App\Service\DocumentOcrService;
use App\Service\DocumentTextIndexer;
use App\Tests\Integration\RepositoryTestCase;
use App\Tests\Support\FakeOcrEngine;
use App\Tests\Support\FixedGlobalSettings;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

/** OCR of scanned files in short turns: where it resumes, what it records and when it does nothing. */
final class DocumentOcrServiceTest extends RepositoryTestCase
{
    private DocumentFileTextRepository $texts;
    private FakeOcrEngine $engine;
    private FixedGlobalSettings $settings;
    private DocumentOcrService $ocr;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var DocumentFileTextRepository $texts */
        $texts       = self::getContainer()->get(DocumentFileTextRepository::class);
        $this->texts = $texts;
        /** @var DocumentFileRepository $files */
        $files          = self::getContainer()->get(DocumentFileRepository::class);
        $this->engine   = new FakeOcrEngine();
        $this->settings = new FixedGlobalSettings(['search.ocr' => 'on']);
        $this->ocr      = new DocumentOcrService($this->engine, $this->settings, $files, $this->texts, new NativeClock(), new NullLogger());
    }

    private function scan(string $filename = 'escaneo.pdf', string $mime = 'application/pdf', TextIndexStatus $status = TextIndexStatus::NoText, ?int $ocrPage = null): DocumentFile
    {
        $file = new DocumentFile(hash('sha256', $filename . random_int(1, PHP_INT_MAX)), 'contenido', $mime, $filename, 9);
        $this->em->persist($file);
        $this->em->flush();
        $this->texts->save($file->getId(), $status, DocumentTextIndexer::VERSION, null, null, null, new \DateTimeImmutable(), $ocrPage);

        return $file;
    }

    /** @return array{status: string, page: int|null, text: string|null} */
    private function state(DocumentFile $file): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, ocr_page, text FROM document_file_text WHERE file_id = ?', [$file->getId()], ['uuid']);
        self::assertIsArray($row);

        return ['status' => (string) $row['status'], 'page' => $row['ocr_page'] === null ? null : (int) $row['ocr_page'], 'text' => \is_string($row['text']) ? $row['text'] : null];
    }

    public function testAScannedPdfIsReadPageByPageAndBecomesSearchable(): void
    {
        $file = $this->scan();

        $units = $this->ocr->run(60);

        self::assertSame(3, $units);
        self::assertSame([1, 2, 3], $this->engine->recognised);
        $state = $this->state($file);
        self::assertSame('indexed', $state['status']);
        self::assertSame(3, $state['page']);
        self::assertStringContainsString('página 1 de prueba', (string) $state['text']);
        self::assertStringContainsString('página 3 de prueba', (string) $state['text']);
        // The folded twin is what the search runs on.
        $folded = $this->em->getConnection()->fetchOne('SELECT folded FROM document_file_text WHERE file_id = ?', [$file->getId()], ['uuid']);
        self::assertIsString($folded);
        self::assertStringContainsString('pagina 2 de prueba', $folded);
    }

    public function testALongScanIsReadOverSeveralTurnsFromWhereItStopped(): void
    {
        $this->engine->pages = 5;
        $file                = $this->scan();

        // A turn with no time left still reads one page (so it always moves forward) and saves where it stopped.
        self::assertSame(1, $this->ocr->run(0));
        $state = $this->state($file);
        self::assertSame('ocr_pending', $state['status']);
        self::assertSame(1, $state['page']);
        self::assertStringContainsString('página 1', (string) $state['text']);

        self::assertSame(1, $this->ocr->run(0));
        self::assertSame(2, $this->state($file)['page']);
        self::assertStringContainsString('página 2', (string) $this->state($file)['text']);

        // The rest, with room to finish.
        self::assertSame(3, $this->ocr->run(60));
        self::assertSame([1, 2, 3, 4, 5], $this->engine->recognised); // no page twice
        self::assertSame('indexed', $this->state($file)['status']);
        self::assertSame(5, $this->state($file)['page']);
    }

    public function testNothingRunsWhenOcrIsOffOrTheToolsAreMissing(): void
    {
        $file = $this->scan();

        $this->settings->values['search.ocr'] = 'off';
        self::assertSame(0, $this->ocr->run(60));

        $this->settings->values['search.ocr'] = 'on';
        $this->engine->available              = false;
        self::assertSame(0, $this->ocr->run(60));

        self::assertSame('no_text', $this->state($file)['status']);
        self::assertNull($this->state($file)['page']);
    }

    public function testAScanWithNothingReadableIsNotTriedAgain(): void
    {
        $this->engine->pages    = 2;
        $this->engine->pageText = [1 => '  ', 2 => '7'];
        $file                   = $this->scan();

        $this->ocr->run(60);

        $state = $this->state($file);
        self::assertSame('no_text', $state['status']);
        self::assertSame(2, $state['page']); // marks that OCR already looked
        self::assertSame(0, $this->ocr->run(60));
    }

    public function testAPageThatCannotBeReadIsSkippedNotTheWholeDocument(): void
    {
        $this->engine->failingPages = [2];
        $file                       = $this->scan();

        $this->ocr->run(60);

        $state = $this->state($file);
        self::assertSame('indexed', $state['status']);
        self::assertStringContainsString('página 1', (string) $state['text']);
        self::assertStringNotContainsString('página 2', (string) $state['text']);
        self::assertStringContainsString('página 3', (string) $state['text']);
    }

    public function testADamagedPdfIsRecordedAsFailedAndNotRetried(): void
    {
        $this->engine->brokenPdf = true;
        $file                    = $this->scan();

        self::assertSame(1, $this->ocr->run(60));

        self::assertSame('failed', $this->state($file)['status']);
        self::assertSame(0, $this->ocr->run(60));
    }

    public function testAnImageIsReadAsOnePage(): void
    {
        $file = $this->scan('foto.png', 'image/png', TextIndexStatus::Unsupported);

        $this->ocr->run(60);

        self::assertSame(1, $this->engine->imagesRecognised);
        self::assertSame('indexed', $this->state($file)['status']);
        self::assertSame('Texto de una imagen escaneada', $this->state($file)['text']);
    }

    public function testOnlyTypesOcrReadsAreOffered(): void
    {
        $this->scan('hoja.xls', 'application/vnd.ms-excel', TextIndexStatus::Unsupported);
        $this->scan('acta.pdf', 'application/pdf', TextIndexStatus::Failed);
        $tooBig = new DocumentFile(hash('sha256', 'big'), 'x', 'application/pdf', 'enorme.pdf', DocumentOcrService::MAX_BYTES + 1);
        $this->em->persist($tooBig);
        $this->em->flush();
        $this->texts->save($tooBig->getId(), TextIndexStatus::TooLarge, DocumentTextIndexer::VERSION, null, null, null, new \DateTimeImmutable());

        self::assertSame(0, $this->ocr->run(60));
        self::assertSame(0, $this->texts->countAwaitingOcr(DocumentOcrService::mimeTypes(), DocumentOcrService::MAX_BYTES));
    }

    public function testFilesUnderWayAreFinishedBeforeNewOnesAreStarted(): void
    {
        $this->engine->pages = 2;
        $waiting             = $this->scan('espera.pdf');
        $underWay            = $this->scan('en-curso.pdf', 'application/pdf', TextIndexStatus::OcrPending, 1);

        // Room for exactly one page: it goes to the one already begun.
        $this->ocr->run(0);

        self::assertSame('indexed', $this->state($underWay)['status']);
        self::assertSame('no_text', $this->state($waiting)['status']);
    }

    public function testRetryingForgetsWhatOcrReadSoTheFileIsReadAgain(): void
    {
        $file = $this->scan();
        $this->ocr->run(60);
        $plain = $this->scan('sin-ocr.pdf');
        self::assertNull($this->state($plain)['page']);

        self::assertSame(1, $this->texts->deleteOcrResults());

        self::assertSame([$file->getId()->toRfc4122()], array_map(static fn ($id): string => $id->toRfc4122(), $this->texts->findIdsToIndex(DocumentTextIndexer::VERSION, 10)));
    }

    public function testTheProgressOfAFileAnotherWorkerAlreadyMovedIsNotOverwritten(): void
    {
        $file = $this->scan();

        // Somebody else already read up to page 2 while this worker still believes the file is untouched.
        self::assertFalse($this->texts->saveOcrProgress($file->getId(), 1, TextIndexStatus::Indexed, 1, 'x', 'x', null, 3, new \DateTimeImmutable()));
        self::assertNull($this->state($file)['page']);
        self::assertTrue($this->texts->saveOcrProgress($file->getId(), null, TextIndexStatus::OcrPending, 1, 'x', 'x', null, 1, new \DateTimeImmutable()));
        self::assertSame(1, $this->state($file)['page']);
    }
}
