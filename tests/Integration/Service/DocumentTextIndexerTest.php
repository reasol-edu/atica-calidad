<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\DocumentFile;
use App\Entity\TextIndexStatus;
use App\MessageHandler\IndexDocumentFileHandler;
use App\Message\IndexDocumentFileMessage;
use App\Repository\DocumentFileRepository;
use App\Repository\DocumentFileTextRepository;
use App\Search\Extractor\TextExtractorInterface;
use App\Service\DocumentCreationService;
use App\Service\DocumentOcrService;
use App\Service\DocumentTextIndexer;
use App\Tests\Integration\RepositoryTestCase;
use App\Tests\Support\FakeOcrEngine;
use App\Tests\Support\FixedGlobalSettings;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;

/** Reading stored files into the search index: what is recorded for each kind of file, the sweep, and the cleanup. */
final class DocumentTextIndexerTest extends RepositoryTestCase
{
    private DocumentTextIndexer $indexer;
    private DocumentFileTextRepository $texts;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var DocumentTextIndexer $indexer */
        $indexer       = self::getContainer()->get(DocumentTextIndexer::class);
        $this->indexer = $indexer;
        /** @var DocumentFileTextRepository $texts */
        $texts       = self::getContainer()->get(DocumentFileTextRepository::class);
        $this->texts = $texts;
    }

    private function file(string $content, string $filename, string $mime = 'text/plain', ?int $size = null): DocumentFile
    {
        $file = new DocumentFile(hash('sha256', $content . $filename . random_int(1, PHP_INT_MAX)), $content, $mime, $filename, $size ?? \strlen($content));
        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    /** @return array<string, mixed>|null the row of the file, or null when it has none */
    private function row(DocumentFile $file): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, extractor_version, text, folded, error FROM document_file_text WHERE file_id = ?', [$file->getId()], ['uuid']);

        return \is_array($row) ? $row : null;
    }

    public function testATextFileIsIndexedWithItsFoldedTwin(): void
    {
        $file = $this->file('Programación Didáctica de Educación Física', 'programacion.txt');

        self::assertTrue($this->indexer->index($file->getId()));

        $row = $this->row($file);
        self::assertNotNull($row);
        self::assertSame('indexed', $row['status']);
        self::assertSame(DocumentTextIndexer::VERSION, (int) $row['extractor_version']);
        self::assertSame('Programación Didáctica de Educación Física', $row['text']);
        self::assertSame('programacion didactica de educacion fisica', $row['folded']);
    }

    public function testNulBytesAndInvalidUtf8NeverReachTheDatabase(): void
    {
        $file = $this->file("Acta\0 de la reuni\xF3n del claustro", 'acta.txt');

        $this->indexer->index($file->getId());

        $row = $this->row($file);
        self::assertNotNull($row);
        self::assertSame('indexed', $row['status']);
        self::assertIsString($row['text']);
        self::assertStringNotContainsString("\0", $row['text']);
        self::assertTrue(mb_check_encoding($row['text'], 'UTF-8'));
        // The byte \xF3 alone is Windows-1252 for "ó".
        self::assertStringContainsString('reunión', $row['text']);
    }

    public function testAnImageIsRecordedAsUnsupported(): void
    {
        $file = $this->file("\x89PNG\r\n\x1a\n", 'foto.png', 'image/png');

        $this->indexer->index($file->getId());

        self::assertSame('unsupported', $this->row($file)['status'] ?? null);
    }

    public function testAFileWithNoTextInItIsRecordedAsSuch(): void
    {
        $file = $this->file("  \n  7  \n", 'vacio.txt');

        $this->indexer->index($file->getId());

        $row = $this->row($file);
        self::assertNotNull($row);
        self::assertSame('no_text', $row['status']);
        self::assertNull($row['text']);
    }

    public function testADamagedDocumentIsRecordedAsFailedWithTheReasonAndIsNotRetried(): void
    {
        $file = $this->file('esto no es un zip', 'roto.docx');

        self::assertTrue($this->indexer->index($file->getId()));

        $row = $this->row($file);
        self::assertSame('failed', $row['status'] ?? null);
        self::assertStringContainsString('ZIP', (string) ($row['error'] ?? ''));
        // The same version would fail the same way: it is not offered again.
        self::assertFalse($this->indexer->index($file->getId()));
        self::assertSame([], $this->texts->findIdsToIndex(DocumentTextIndexer::VERSION, 10));
    }

    /** An indexer whose only extractor runs $onExtract (and then returns some text), to look at what is recorded while a file is being read. */
    private function indexerWith(\Closure $onExtract): DocumentTextIndexer
    {
        /** @var DocumentFileRepository $files */
        $files = self::getContainer()->get(DocumentFileRepository::class);

        $extractor = new class($onExtract) implements TextExtractorInterface {
            public function __construct(private readonly \Closure $onExtract) {}

            public function supports(string $mimeType, string $filename): bool
            {
                return true;
            }

            public function maxFileBytes(): int
            {
                return 1_000_000;
            }

            public function extract(string $path, int $maxChars): string
            {
                ($this->onExtract)();

                return 'Texto de prueba suficientemente largo.';
            }
        };

        return new DocumentTextIndexer([$extractor], $files, $this->texts, $this->ocrService(), new NativeClock(), new NullLogger());
    }

    /** The OCR service with a fake engine; turned off (and its engine absent) unless $enabled. */
    private function ocrService(bool $enabled = false, ?FakeOcrEngine $engine = null): DocumentOcrService
    {
        /** @var DocumentFileRepository $files */
        $files = self::getContainer()->get(DocumentFileRepository::class);

        return new DocumentOcrService(
            $engine ?? new FakeOcrEngine(),
            new FixedGlobalSettings(['search.ocr' => $enabled ? 'on' : 'off']),
            $files,
            $this->texts,
            new NativeClock(),
            new NullLogger(),
        );
    }

    /** @return array<string, mixed>|null */
    private function ocrRow(DocumentFile $file): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, ocr_page, text FROM document_file_text WHERE file_id = ?', [$file->getId()], ['uuid']);

        return \is_array($row) ? $row : null;
    }

    /** An indexer whose reader finds $text in every file (and has no size limit that matters), with OCR on or off. */
    private function ocrIndexer(string $text, bool $ocrOn, FakeOcrEngine $engine): DocumentTextIndexer
    {
        /** @var DocumentFileRepository $files */
        $files = self::getContainer()->get(DocumentFileRepository::class);

        $extractor = new class($text) implements TextExtractorInterface {
            public function __construct(private readonly string $text) {}

            public function supports(string $mimeType, string $filename): bool
            {
                return $mimeType === 'application/pdf';
            }

            public function maxFileBytes(): int
            {
                return 1_000;
            }

            public function extract(string $path, int $maxChars): string
            {
                return $this->text;
            }
        };

        return new DocumentTextIndexer([$extractor], $files, $this->texts, $this->ocrService($ocrOn, $engine), new NativeClock(), new NullLogger());
    }

    public function testAScannedPdfStaysAsNoTextWhenOcrIsOff(): void
    {
        $engine = new FakeOcrEngine();
        $file   = $this->file('x', 'escaneo.pdf', 'application/pdf');

        $this->ocrIndexer('', false, $engine)->index($file->getId());

        $row = $this->ocrRow($file);
        self::assertNotNull($row);
        self::assertSame('no_text', $row['status']);
        self::assertNull($row['ocr_page']);
        self::assertSame(0, $engine->pageCountCalls);
    }

    public function testAScannedPdfIsQueuedForOcrWhenItIsOn(): void
    {
        $file = $this->file('x', 'escaneo.pdf', 'application/pdf');

        $this->ocrIndexer('', true, new FakeOcrEngine())->index($file->getId());

        $row = $this->ocrRow($file);
        self::assertSame('ocr_pending', $row['status'] ?? null);
        self::assertSame(0, (int) ($row['ocr_page'] ?? -1));
    }

    public function testAPdfWithAStampButScannedPagesIsAlsoQueued(): void
    {
        $engine        = new FakeOcrEngine();
        $engine->pages = 30;
        $file          = $this->file('x', 'firmado.pdf', 'application/pdf');

        // 30 pages and one line of stamp text: far less than a page's worth of text per page.
        $this->ocrIndexer('Firmado electrónicamente por Dirección', true, $engine)->index($file->getId());

        $row = $this->ocrRow($file);
        self::assertSame('ocr_pending', $row['status'] ?? null);
        // What the normal reader did find stays searchable until OCR replaces it.
        self::assertSame('Firmado electrónicamente por Dirección', $row['text'] ?? null);
    }

    public function testAPdfWithRealTextIsNotSentToOcr(): void
    {
        $engine        = new FakeOcrEngine();
        $engine->pages = 2;
        $file          = $this->file('x', 'acta.pdf', 'application/pdf');

        $this->ocrIndexer(str_repeat('Texto normal de un acta con contenido real. ', 10), true, $engine)->index($file->getId());

        self::assertSame('indexed', $this->ocrRow($file)['status'] ?? null);
    }

    public function testAnImageIsQueuedForOcrWhenItIsOnAndUnsupportedWhenOff(): void
    {
        $on  = $this->file("\x89PNG\r\n\x1a\n", 'foto.png', 'image/png');
        $off = $this->file("\x89PNG\r\n\x1a\n", 'otra.png', 'image/png');

        $this->ocrIndexer('', true, new FakeOcrEngine())->index($on->getId());
        $this->ocrIndexer('', false, new FakeOcrEngine())->index($off->getId());

        self::assertSame('ocr_pending', $this->ocrRow($on)['status'] ?? null);
        self::assertSame('unsupported', $this->ocrRow($off)['status'] ?? null);
    }

    public function testAPdfTooBigForTheNormalReaderGoesToOcrWhenItIsOn(): void
    {
        $big = $this->file('x', 'gordo.pdf', 'application/pdf', 5_000); // the test reader's limit is 1000 bytes

        $this->ocrIndexer('', true, new FakeOcrEngine())->index($big->getId());

        self::assertSame('ocr_pending', $this->ocrRow($big)['status'] ?? null);
    }

    public function testOcrOnButToolsMissingChangesNothing(): void
    {
        $engine            = new FakeOcrEngine();
        $engine->available = false;
        $file              = $this->file('x', 'escaneo.pdf', 'application/pdf');

        $this->ocrIndexer('', true, $engine)->index($file->getId());

        self::assertSame('no_text', $this->ocrRow($file)['status'] ?? null);
    }

    public function testAFileIsMarkedAsInterruptedWhileItIsBeingReadSoACrashCannotRepeatForEver(): void
    {
        $file = $this->file('contenido', 'a.txt');

        $duringRead = null;
        $indexer    = $this->indexerWith(function () use ($file, &$duringRead): void {
            $duringRead = $this->row($file);
        });

        self::assertTrue($indexer->index($file->getId()));

        // While the extractor ran, the file already had a "failed" row: if the process had died there, it would not be offered again...
        self::assertSame('failed', $duringRead['status'] ?? null);
        self::assertSame(DocumentTextIndexer::INTERRUPTED, $duringRead['error'] ?? null);
        // ...and once it finished, the real outcome replaced it.
        $row = $this->row($file);
        self::assertNotNull($row);
        self::assertSame('indexed', $row['status']);
        self::assertNull($row['error']);
    }

    public function testAFileThatKilledTheWorkerIsNeitherOpenedAgainNorOfferedByTheSweep(): void
    {
        $file = $this->file('contenido de un fichero que agotó la memoria', 'pesado.pdf', 'application/pdf');
        // What is left behind when the process dies while reading: the provisional row, never replaced.
        $this->texts->save($file->getId(), TextIndexStatus::Failed, DocumentTextIndexer::VERSION, null, null, DocumentTextIndexer::INTERRUPTED, new \DateTimeImmutable());

        self::assertFalse($this->indexer->index($file->getId()));
        self::assertSame(0, $this->indexer->sweep());
        self::assertSame([], $this->texts->findIdsToIndex(DocumentTextIndexer::VERSION, 10));
    }

    public function testAnExtractorThatBlowsUpWithAnErrorIsAVerdictOnTheFileNotACrashOfTheWorker(): void
    {
        $file    = $this->file('contenido', 'raro.txt');
        $indexer = $this->indexerWith(static function (): void {
            throw new \TypeError('algo raro dentro del parser');
        });

        self::assertTrue($indexer->index($file->getId()));

        $row = $this->row($file);
        self::assertSame('failed', $row['status'] ?? null);
        self::assertSame('Error inesperado al leer el fichero.', $row['error'] ?? null);
    }

    public function testTheMemoryAndTimeLimitsAreBackWhereTheyWereAfterReadingAFile(): void
    {
        $memory  = \ini_get('memory_limit');
        $seconds = \ini_get('max_execution_time');
        $inside  = null;
        $indexer = $this->indexerWith(static function () use (&$inside): void {
            $inside = \ini_get('max_execution_time');
        });

        $indexer->index($this->file('contenido', 'a.txt')->getId());

        self::assertSame('120', $inside, 'a ceiling on the reading time while it runs');
        self::assertSame($memory, \ini_get('memory_limit'));
        self::assertSame($seconds, \ini_get('max_execution_time'));
    }

    public function testAFileBiggerThanItsExtractorAllowsIsRecordedAsTooLarge(): void
    {
        $file = $this->file('x', 'enorme.txt', 'text/plain', 500 * 1024 * 1024);

        $this->indexer->index($file->getId());

        self::assertSame('too_large', $this->row($file)['status'] ?? null);
    }

    public function testAFileAlreadyReadByThisVersionIsLeftAloneAndAnOlderRowIsReadAgain(): void
    {
        $file = $this->file('Texto original', 'a.txt');
        $this->indexer->index($file->getId());
        self::assertFalse($this->indexer->index($file->getId()));

        // A row written by an older version of the extractor (and with stale text) is replaced.
        $this->texts->save($file->getId(), TextIndexStatus::Indexed, 0, 'viejo', 'viejo', null, new \DateTimeImmutable());
        self::assertTrue($this->indexer->index($file->getId()));
        self::assertSame('Texto original', $this->row($file)['text'] ?? null);
    }

    public function testASweepReadsOnlyWhatIsPendingAndStopsWhenThereIsNothingLeft(): void
    {
        $done    = $this->file('Ya leído', 'a.txt');
        $first   = $this->file('Primero pendiente', 'b.txt');
        $second  = $this->file('Segundo pendiente', 'c.txt');
        $this->indexer->index($done->getId());

        self::assertSame(2, $this->indexer->sweep());
        self::assertNotNull($this->row($first));
        self::assertNotNull($this->row($second));
        self::assertSame(0, $this->indexer->sweep());
    }

    public function testASweepHonoursItsFileLimit(): void
    {
        $this->file('Uno', 'a.txt');
        $this->file('Dos', 'b.txt');
        $this->file('Tres', 'c.txt');

        self::assertSame(2, $this->indexer->sweep(2));
        self::assertSame(1, $this->indexer->sweep(2));
    }

    public function testRetryingTheFailedOnesMakesTheSweepReadThemAgain(): void
    {
        $file = $this->file('esto no es un zip', 'roto.docx');
        $this->indexer->index($file->getId());
        self::assertSame(0, $this->indexer->sweep());

        self::assertSame(1, $this->texts->deleteByStatus(TextIndexStatus::Failed));
        self::assertSame(1, $this->indexer->sweep());
    }

    public function testCountsByStatusIncludeWhatIsStillPending(): void
    {
        $this->indexer->index($this->file('Texto con contenido', 'a.txt')->getId());
        $this->indexer->index($this->file('no zip', 'b.docx')->getId());
        $this->file('Sin leer todavía', 'c.txt');

        $counts = $this->texts->countByStatus(DocumentTextIndexer::VERSION);

        self::assertSame(1, $counts['indexed']);
        self::assertSame(1, $counts['failed']);
        self::assertSame(1, $counts['pending']);
    }

    public function testThePurgeOfOrphanFilesTakesTheirTextWithThem(): void
    {
        $file = $this->file('Texto de un fichero huérfano', 'huerfano.txt');
        $this->indexer->index($file->getId());
        self::assertNotNull($this->row($file));

        /** @var DocumentFileRepository $files */
        $files = self::getContainer()->get(DocumentFileRepository::class);
        self::assertSame(1, $files->deleteOrphans());

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM document_file_text'));
    }

    public function testStoringANewFileQueuesItsIndexingAndAReusedOneDoesNot(): void
    {
        /** @var DocumentCreationService $creation */
        $creation = self::getContainer()->get(DocumentCreationService::class);

        $file = $creation->storeFile('Contenido nuevo para el índice', 'text/plain', 'nuevo.txt');
        // In the test environment the message runs inline, so the row is already there.
        self::assertSame('indexed', $this->row($file)['status'] ?? null);

        $this->em->getConnection()->executeStatement('DELETE FROM document_file_text');
        $again = $creation->storeFile('Contenido nuevo para el índice', 'text/plain', 'nuevo.txt');
        self::assertSame($file->getId()->toRfc4122(), $again->getId()->toRfc4122());
        self::assertNull($this->row($again), 'a file that was already stored is not queued again; the sweep takes care of it');
    }

    public function testTheHandlerIgnoresAnInvalidIdAndReadsAValidOne(): void
    {
        $file = $this->file('Texto para el manejador', 'h.txt');
        /** @var IndexDocumentFileHandler $handler */
        $handler = self::getContainer()->get(IndexDocumentFileHandler::class);

        $handler(new IndexDocumentFileMessage('no-es-un-uuid'));
        self::assertNull($this->row($file));

        $handler(new IndexDocumentFileMessage($file->getId()->toRfc4122()));
        self::assertSame('indexed', $this->row($file)['status'] ?? null);
    }

    public function testTheCommandReadsEverythingPendingAndCanStartOver(): void
    {
        $a = $this->file('Primer texto', 'a.txt');
        $this->file('Segundo texto', 'b.txt');

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:index-documents'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 ficheros', $tester->getDisplay());
        self::assertSame('indexed', $this->row($a)['status'] ?? null);

        $tester->execute([]);
        self::assertStringContainsString('No había ficheros pendientes', $tester->getDisplay());

        $tester->execute(['--reindex' => true]);
        self::assertStringContainsString('2 ficheros', $tester->getDisplay());
    }
}
