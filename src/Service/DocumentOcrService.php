<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentFileText;
use App\Entity\TextIndexStatus;
use App\Repository\DocumentFileRepository;
use App\Repository\DocumentFileTextRepository;
use App\Search\Ocr\OcrEngineInterface;
use App\Search\Ocr\OcrException;
use App\Search\Ocr\OcrUnavailableException;
use App\Search\TextNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * OCR for scanned documents, an optional extra of the search inside documents. Nothing here runs unless the administrator turned
 * "search.ocr" on AND the tools are installed (OcrEngineInterface::isAvailable()): without them every scan is recorded as having
 * no text, exactly as before.
 *
 * A scan can have hundreds of pages and reading one takes seconds, while the same worker also sends the e-mails and makes the
 * backups, and a message cannot be interrupted once it starts. So the work is done in short turns: run() reads pages for a minute
 * at most, saves where it stopped (DocumentFileText::$ocrPage, with the text so far already searchable) and returns; the
 * scheduler calls it again two minutes later, and it picks up from the saved page. Nothing is lost if the worker restarts.
 */
final class DocumentOcrService
{
    public const string SETTING = 'search.ocr';

    public const array PDF_TYPES   = ['application/pdf'];
    public const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/tiff'];

    /** Pages read from one document; a longer one is searched up to here (and up to DocumentFileText::MAX_CHARS). */
    public const int MAX_PAGES = 500;

    /** Biggest file OCR opens. It never holds the file in PHP's memory, so this is a disk and time limit, not a memory one. */
    public const int MAX_BYTES = 200 * 1048576;

    /** Seconds one turn may spend reading pages before saving its progress (a page is never cut halfway). */
    public const int TURN_SECONDS = 60;

    /** A PDF with fewer characters per page than this on average is treated as a scan (a stamp or a footer is not text). */
    private const int MIN_CHARS_PER_PAGE = 40;

    /** Files looked at per turn at most. */
    private const int FILES_PER_TURN = 10;

    public function __construct(
        private readonly OcrEngineInterface $engine,
        private readonly AppSettingsInterface $settings,
        private readonly DocumentFileRepository $files,
        private readonly DocumentFileTextRepository $texts,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /** The administrator turned OCR on (whether or not the tools are there). */
    public function isEnabled(): bool
    {
        return $this->settings->getGlobal(self::SETTING) === 'on';
    }

    /** OCR will actually run: turned on and every tool installed. */
    public function isUsable(): bool
    {
        return $this->isEnabled() && $this->engine->isAvailable();
    }

    /** @return list<string> the MIME types OCR reads */
    public static function mimeTypes(): array
    {
        return [...self::PDF_TYPES, ...self::IMAGE_TYPES];
    }

    public static function isPdf(string $mimeType): bool
    {
        return \in_array(strtolower($mimeType), self::PDF_TYPES, true);
    }

    public static function isImage(string $mimeType): bool
    {
        return \in_array(strtolower($mimeType), self::IMAGE_TYPES, true);
    }

    /** Whether OCR would read a file of this type and size when it is usable. */
    public function canRead(string $mimeType, int $size): bool
    {
        return $size <= self::MAX_BYTES && (self::isPdf($mimeType) || self::isImage($mimeType));
    }

    /**
     * Whether a PDF whose normal reading gave $cleanText is a scan, or has scanned pages: too little text for its number of
     * pages. False whenever OCR is not usable, so nothing changes for an installation without it.
     */
    public function looksScanned(string $mimeType, string $pdfPath, string $cleanText): bool
    {
        if (!self::isPdf($mimeType) || !$this->isUsable()) {
            return false;
        }

        $chars = mb_strlen($cleanText);
        if ($chars < 3) {
            return true;
        }

        try {
            $pages = $this->engine->pdfPageCount($pdfPath);
        } catch (OcrException) {
            return false; // the normal reader got text from it: keep that
        }

        return intdiv($chars, max(1, $pages)) < self::MIN_CHARS_PER_PAGE;
    }

    /**
     * One turn of work: reads pages of the files that wait for OCR for about $seconds, saving its progress, and returns how many
     * units it did (pages read plus files that could not be read), 0 when there is nothing left to do or OCR is not usable.
     */
    public function run(int $seconds = self::TURN_SECONDS): int
    {
        if (!$this->isUsable()) {
            return 0;
        }

        $deadline = $this->clock->now()->getTimestamp() + $seconds;
        $done     = 0;

        foreach ($this->texts->findIdsForOcr(self::mimeTypes(), self::MAX_BYTES, self::FILES_PER_TURN) as $id) {
            if ($done > 0 && $this->clock->now()->getTimestamp() >= $deadline) {
                break;
            }
            $done += $this->readFile($id, $deadline);
        }

        return $done;
    }

    /** Reads pages of one file from where it stopped, until $deadline; returns the units of work done. */
    private function readFile(Uuid $fileId, int $deadline): int
    {
        $state = $this->texts->findOcrState($fileId);
        $meta  = $this->files->findMetadata($fileId);
        if ($state === null || $meta === null) {
            return 0;
        }

        $expected = $state['page'];
        $isPdf    = self::isPdf($meta['mimeType']);
        $page     = $state['status'] === TextIndexStatus::OcrPending ? ($state['page'] ?? 0) : 0;
        // A file queued by the normal reader (page 0) may carry the text it did find; the OCR text replaces it.
        $text = $page > 0 ? ($state['text'] ?? '') : '';

        $tmp = tempnam(sys_get_temp_dir(), 'atica-ocr-');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear un fichero temporal para el OCR.');
        }

        $read  = 0;
        $total = 1;

        try {
            $this->files->writeContentTo($fileId, $tmp);

            try {
                $total = $isPdf ? min($this->engine->pdfPageCount($tmp), self::MAX_PAGES) : 1;
            } catch (OcrUnavailableException) {
                return 0;
            } catch (OcrException $e) {
                $this->logger->info('OCR: file {file} unreadable: {reason}', ['file' => $fileId->toRfc4122(), 'reason' => $e->getMessage()]);
                $this->texts->saveOcrProgress($fileId, $expected, TextIndexStatus::Failed, DocumentTextIndexer::VERSION, null, null, mb_strimwidth($e->getMessage(), 0, 250, '…'), $page, $this->clock->now());

                return 1;
            }

            while ($page < $total) {
                if ($read > 0 && $this->clock->now()->getTimestamp() >= $deadline) {
                    break;
                }

                try {
                    $chunk = $isPdf ? $this->engine->recognizePdfPage($tmp, $page + 1) : $this->engine->recognizeImage($tmp);
                } catch (OcrUnavailableException) {
                    break; // the tools went away: keep what was read and wait
                } catch (OcrException $e) {
                    // One page that cannot be read (a tool timed out) costs that page, not the whole document.
                    $this->logger->info('OCR: file {file} page {page} skipped: {reason}', ['file' => $fileId->toRfc4122(), 'page' => $page + 1, 'reason' => $e->getMessage()]);
                    $chunk = '';
                }

                $text .= "\n\n" . $chunk;
                ++$page;
                ++$read;

                if (mb_strlen($text) >= DocumentFileText::MAX_CHARS * 2) {
                    $total = $page; // a document this long is searched up to here
                }
            }
        } finally {
            @unlink($tmp);
        }

        if ($read === 0) {
            return 0;
        }

        $text = TextNormalizer::clean($text);
        $text = mb_strlen($text) > DocumentFileText::MAX_CHARS ? mb_substr($text, 0, DocumentFileText::MAX_CHARS) : $text;
        $done = $page >= $total || mb_strlen($text) >= DocumentFileText::MAX_CHARS;

        $status = $done ? (mb_strlen($text) < 3 ? TextIndexStatus::NoText : TextIndexStatus::Indexed) : TextIndexStatus::OcrPending;
        $keep   = mb_strlen($text) < 3 ? null : $text;

        $this->texts->saveOcrProgress(
            $fileId,
            $expected,
            $status,
            DocumentTextIndexer::VERSION,
            $keep,
            $keep === null ? null : TextNormalizer::fold($keep),
            null,
            $page,
            $this->clock->now(),
        );

        return $read;
    }
}
