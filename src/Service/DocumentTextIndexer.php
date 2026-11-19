<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentFileText;
use App\Entity\TextIndexStatus;
use App\Repository\DocumentFileRepository;
use App\Repository\DocumentFileTextRepository;
use App\Search\Extractor\ExtractionException;
use App\Search\Extractor\TextExtractorInterface;
use App\Search\TextNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Reads a stored file and records its text for the search: picks the extractor for its type, cleans and trims the text and
 * writes one row (DocumentFileText) with the outcome, success or not. The worker runs it for each new file and, in sweeps, for
 * everything that was never read or was read by an older VERSION.
 *
 * It never throws for a file it cannot read: that is an outcome (a "failed" row with the reason), not an error to retry. It only
 * lets infrastructure errors (the database is down) propagate, which Messenger does retry. It touches the database through
 * plain SQL only, so it is safe to run inside a request that has just stored the file.
 */
final class DocumentTextIndexer
{
    /**
     * Version of the reading code. Raise it when an extractor starts to read more (or better) and every file is read again by
     * the next sweep.
     */
    public const int VERSION = 1;

    /** Files read per sweep run, and seconds after which a sweep stops starting new ones. */
    public const int SWEEP_FILES   = 25;
    public const int SWEEP_SECONDS = 45;

    /** Memory and time one file may use while it is read. */
    private const int EXTRACTION_MEMORY_BYTES = 512 * 1048576;
    private const int EXTRACTION_SECONDS      = 120;

    /** Shown for a file whose reading never finished (the worker died on it: out of memory, or stuck). */
    public const string INTERRUPTED = 'La lectura no terminó (el fichero agotó la memoria o tardó demasiado). Si ya se ha resuelto la causa, se puede reintentar.';

    /** @param iterable<TextExtractorInterface> $extractors */
    public function __construct(
        #[AutowireIterator('app.text_extractor')]
        private readonly iterable $extractors,
        private readonly DocumentFileRepository $files,
        private readonly DocumentFileTextRepository $texts,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /** Reads one file unless it already has a row from this version; returns whether it did any work. */
    public function index(Uuid $fileId): bool
    {
        if ($this->texts->isCurrent($fileId, self::VERSION)) {
            return false;
        }

        $meta = $this->files->findMetadata($fileId);
        if ($meta === null) {
            return false; // the file went away (a purge) before the worker got to it
        }

        $extractor = $this->extractorFor($meta['mimeType'], $meta['originalFilename']);
        if ($extractor === null) {
            $this->record($fileId, TextIndexStatus::Unsupported, null, null);

            return true;
        }
        if ($meta['size'] > $extractor->maxFileBytes()) {
            $this->record($fileId, TextIndexStatus::TooLarge, null, null);

            return true;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'atica-idx-');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear un fichero temporal para indexar.');
        }

        // Written BEFORE reading: a file that exhausts the memory or hangs its parser kills the worker (a fatal error cannot be
        // caught), and without a row it would be the first one the sweep offers again on every restart, taking down the emails,
        // reminders and backups that share the worker. With this row the same version never opens it a second time; the real
        // outcome below replaces it, and "Reintentar los ficheros que fallaron" is the way back once the cause is fixed.
        $this->record($fileId, TextIndexStatus::Failed, null, self::INTERRUPTED);

        try {
            // Not caught: a database problem here is for Messenger to retry, not a verdict on the file.
            $this->files->writeContentTo($fileId, $tmp);

            try {
                $raw = $this->withExtractionLimits(static fn (): string => $extractor->extract($tmp, DocumentFileText::MAX_CHARS));
            } catch (ExtractionException $e) {
                $this->logger->info('Text index: file {file} unreadable: {reason}', ['file' => $fileId->toRfc4122(), 'reason' => $e->getMessage()]);
                $this->record($fileId, TextIndexStatus::Failed, null, mb_strimwidth($e->getMessage(), 0, 250, '…'));

                return true;
            } catch (\Throwable $e) {
                // A parser tripping over a strange file (a TypeError deep inside) says nothing about the infrastructure: same verdict.
                $this->logger->warning('Text index: file {file} made its extractor fail: {reason}', ['file' => $fileId->toRfc4122(), 'reason' => $e->getMessage()]);
                $this->record($fileId, TextIndexStatus::Failed, null, 'Error inesperado al leer el fichero.');

                return true;
            }
        } finally {
            @unlink($tmp);
        }

        $text = TextNormalizer::clean($raw);
        $text = mb_strlen($text) > DocumentFileText::MAX_CHARS ? mb_substr($text, 0, DocumentFileText::MAX_CHARS) : $text;
        // A document whose only "text" is a page number or a stray character is, for any search, a scan.
        $this->record($fileId, mb_strlen($text) < 3 ? TextIndexStatus::NoText : TextIndexStatus::Indexed, mb_strlen($text) < 3 ? null : $text, null);

        return true;
    }

    /**
     * Reads the next files that need it, up to SWEEP_FILES or SWEEP_SECONDS, whichever comes first.
     *
     * @return int how many files were read
     */
    public function sweep(int $maxFiles = self::SWEEP_FILES, int $maxSeconds = self::SWEEP_SECONDS): int
    {
        $deadline = $this->clock->now()->getTimestamp() + $maxSeconds;
        $done     = 0;

        foreach ($this->texts->findIdsToIndex(self::VERSION, $maxFiles) as $id) {
            if ($this->clock->now()->getTimestamp() >= $deadline) {
                break;
            }
            $done += $this->index($id) ? 1 : 0;
        }

        return $done;
    }

    /**
     * Runs a read with the room a big file needs (the PHP command line defaults to 128 MB, and a parser can take several times the
     * size of a PDF) and a ceiling on time (a parser stuck in a loop must end, not hold the worker for ever), then puts both back:
     * the same process goes on to send e-mails, and in a request (tests, the sync transport) the web limit must survive.
     *
     * @param \Closure(): string $read
     */
    private function withExtractionLimits(\Closure $read): string
    {
        $memory  = (string) \ini_get('memory_limit');
        $seconds = (int) \ini_get('max_execution_time');

        if (self::bytes($memory) !== null && self::bytes($memory) < self::EXTRACTION_MEMORY_BYTES) {
            @ini_set('memory_limit', (string) self::EXTRACTION_MEMORY_BYTES);
        }
        @set_time_limit(self::EXTRACTION_SECONDS);

        try {
            return $read();
        } finally {
            @ini_set('memory_limit', $memory);
            @set_time_limit($seconds);
        }
    }

    /** A php.ini size ("256M", "1G", "-1") in bytes; null when unlimited or unreadable. */
    private static function bytes(string $size): ?int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1' || !is_numeric(substr($size, 0, 1))) {
            return null;
        }

        return (int) $size * match (strtoupper(substr($size, -1))) {
            'G'     => 1073741824,
            'M'     => 1048576,
            'K'     => 1024,
            default => 1,
        };
    }

    private function extractorFor(string $mimeType, string $filename): ?TextExtractorInterface
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mimeType, $filename)) {
                return $extractor;
            }
        }

        return null;
    }

    private function record(Uuid $fileId, TextIndexStatus $status, ?string $text, ?string $error): void
    {
        $this->texts->save(
            $fileId,
            $status,
            self::VERSION,
            $text,
            $text === null ? null : TextNormalizer::fold($text),
            $error,
            $this->clock->now(),
        );
    }
}
