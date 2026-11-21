<?php

declare(strict_types=1);

namespace App\Search\Ocr;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * OCR with Tesseract (recognition) and poppler-utils (pdfinfo and pdftoppm, to turn a PDF page into a picture). Optional: the
 * application works without them and only the scanned documents stay unsearchable. Each tool is looked for in the PATH (plus the
 * usual install folders) unless APP_OCR_TESSERACT, APP_OCR_PDFTOPPM or APP_OCR_PDFINFO point to it, which is what a Windows
 * install needs.
 *
 * Programs are always run as an argument list (never through a shell), one page at a time, in a temporary folder that is always
 * removed, with a time limit each: a page that hangs the recogniser costs that page, not the worker.
 */
final class TesseractOcrEngine implements OcrEngineInterface
{
    /** Languages the recognition uses when installed; Spanish is required, English is added for the English words that appear in any document. */
    private const string REQUIRED_LANGUAGE = 'spa';
    private const array OPTIONAL_LANGUAGES = ['eng'];

    /** Width, in pixels, a PDF page is drawn at: about 300 dpi on an A4 sheet, and bounded whatever the page size is. */
    private const int PAGE_WIDTH_PX = 2480;

    private const int INFO_TIMEOUT      = 30;
    private const int RASTER_TIMEOUT    = 90;
    private const int RECOGNIZE_TIMEOUT = 120;

    /** @var array<string, string|null> tool name → absolute path, null when not found */
    private array $paths = [];

    private ?string $languages = null;
    private ?string $reason    = null;
    private bool $checked      = false;

    public function __construct(
        #[Autowire(env: 'default::APP_OCR_TESSERACT')]
        private readonly ?string $tesseractPath = null,
        #[Autowire(env: 'default::APP_OCR_PDFTOPPM')]
        private readonly ?string $pdftoppmPath = null,
        #[Autowire(env: 'default::APP_OCR_PDFINFO')]
        private readonly ?string $pdfinfoPath = null,
    ) {}

    public function isAvailable(): bool
    {
        $this->check();

        return $this->languages !== null;
    }

    public function unavailableReason(): ?string
    {
        $this->check();

        return $this->reason;
    }

    public function languages(): ?string
    {
        $this->check();

        return $this->languages;
    }

    public function pdfPageCount(string $pdfPath): int
    {
        $out = $this->run([$this->tool('pdfinfo'), $pdfPath], self::INFO_TIMEOUT, 'pdfinfo');
        if (preg_match('/^Pages:\s+(\d+)/m', $out, $m) !== 1 || (int) $m[1] < 1) {
            throw new OcrException('No se pudo contar las páginas del PDF.');
        }

        return (int) $m[1];
    }

    public function recognizePdfPage(string $pdfPath, int $page): string
    {
        $dir = $this->tempDir();

        try {
            $prefix = $dir . \DIRECTORY_SEPARATOR . 'page';
            $this->run([
                $this->tool('pdftoppm'), '-f', (string) $page, '-l', (string) $page,
                '-scale-to-x', (string) self::PAGE_WIDTH_PX, '-scale-to-y', '-1',
                '-gray', '-png', '-singlefile', $pdfPath, $prefix,
            ], self::RASTER_TIMEOUT, 'pdftoppm');

            if (!is_file($prefix . '.png')) {
                throw new OcrException(\sprintf('La página %d no se pudo convertir en imagen.', $page));
            }

            return $this->recognize($prefix . '.png', ['--dpi', '300']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function recognizeImage(string $imagePath): string
    {
        return $this->recognize($imagePath, []);
    }

    /** @param list<string> $extra */
    private function recognize(string $image, array $extra): string
    {
        $this->check();
        if ($this->languages === null) {
            throw new OcrUnavailableException($this->reason ?? 'OCR no disponible.');
        }

        return $this->run(
            [$this->tool('tesseract'), $image, 'stdout', '-l', $this->languages, ...$extra],
            self::RECOGNIZE_TIMEOUT,
            'tesseract',
        );
    }

    /** Looks for the tools and the language data once per process. */
    private function check(): void
    {
        if ($this->checked) {
            return;
        }
        $this->checked = true;

        $missing = [];
        foreach (['tesseract', 'pdftoppm', 'pdfinfo'] as $name) {
            if ($this->path($name) === null) {
                $missing[] = $name === 'tesseract' ? 'Tesseract' : 'poppler-utils (' . $name . ')';
            }
        }
        if ($missing !== []) {
            $this->reason = 'Faltan herramientas: ' . implode(', ', $missing) . '.';

            return;
        }

        try {
            $out = $this->run([$this->tool('tesseract'), '--list-langs'], self::INFO_TIMEOUT, 'tesseract');
        } catch (OcrException $e) {
            $this->reason = 'Tesseract no responde: ' . $e->getMessage();

            return;
        }

        $installed = array_map('trim', preg_split('/\R/', $out) ?: []);
        if (!\in_array(self::REQUIRED_LANGUAGE, $installed, true)) {
            $this->reason = 'Falta el idioma español de Tesseract («spa»).';

            return;
        }

        $this->languages = implode('+', [self::REQUIRED_LANGUAGE, ...array_values(array_intersect(self::OPTIONAL_LANGUAGES, $installed))]);
    }

    private function tool(string $name): string
    {
        return $this->path($name) ?? throw new OcrUnavailableException(\sprintf('La herramienta «%s» no está instalada.', $name));
    }

    private function path(string $name): ?string
    {
        if (\array_key_exists($name, $this->paths)) {
            return $this->paths[$name];
        }

        $configured = match ($name) {
            'tesseract' => $this->tesseractPath,
            'pdftoppm'  => $this->pdftoppmPath,
            default     => $this->pdfinfoPath,
        };
        if ($configured !== null && $configured !== '') {
            return $this->paths[$name] = is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return $this->paths[$name] = (new ExecutableFinder())->find($name, null, [
            '/usr/local/bin', '/opt/homebrew/bin', '/usr/bin',
            'C:\\Program Files\\Tesseract-OCR', 'C:\\Program Files (x86)\\Tesseract-OCR',
        ]);
    }

    /**
     * @param non-empty-list<string> $command
     */
    private function run(array $command, int $timeout, string $what): string
    {
        $process = new Process($command, null, ['OMP_THREAD_LIMIT' => '1'], null, $timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            // A timeout (ProcessTimedOutException) or a program that could not be started.
            throw new OcrException(mb_strimwidth(\sprintf('%s no terminó: %s', $what, $e->getMessage()), 0, 240, '…'), 0, $e);
        }

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());

            throw new OcrException(mb_strimwidth(\sprintf('%s falló: %s', $what, $error !== '' ? $error : 'código ' . $process->getExitCode()), 0, 240, '…'));
        }

        // tesseract prints the text on stdout; pdfinfo too. Both ignore stderr warnings.
        return $process->getOutput();
    }

    private function tempDir(): string
    {
        $base = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'atica-ocr-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0o700) && !is_dir($base)) {
            throw new OcrException('No se pudo crear una carpeta temporal para el OCR.');
        }

        return $base;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
