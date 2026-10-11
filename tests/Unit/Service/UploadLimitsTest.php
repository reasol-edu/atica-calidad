<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AppSettingsInterface;
use App\Service\UploadLimits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UploadLimitsTest extends TestCase
{
    /**
     * @param array<string, int|null>    $settings the global "uploads.*" settings
     * @param array<string, string>      $ini      PHP directives (what ini_get() would return)
     */
    private function limits(array $settings = [], array $ini = []): UploadLimits
    {
        $appSettings = $this->createStub(AppSettingsInterface::class);
        $appSettings->method('getGlobal')->willReturnCallback(static fn (string $key): mixed => $settings[$key] ?? null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id . ' ' . json_encode($parameters, \JSON_UNESCAPED_UNICODE),
        );

        $defaults = ['upload_max_filesize' => '32M', 'post_max_size' => '128M', 'max_file_uploads' => '50'];

        return new class($appSettings, $translator, $ini + $defaults) extends UploadLimits {
            /** @param array<string, string> $ini */
            public function __construct(AppSettingsInterface $settings, TranslatorInterface $translator, private readonly array $ini)
            {
                parent::__construct($settings, $translator);
            }

            protected function iniValue(string $directive): string
            {
                return $this->ini[$directive] ?? '';
            }
        };
    }

    private function upload(string $name, int $size, int $error = \UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, str_repeat('a', $size));

        return new UploadedFile($path, $name, 'text/plain', $error, true);
    }

    public function testDefaultsApplyWhenNothingIsConfigured(): void
    {
        $limits = $this->limits();

        self::assertSame(20 * 1024 * 1024, $limits->maxFileBytes());
        self::assertSame(100 * 1024 * 1024, $limits->maxTotalBytes());
        self::assertSame(49, $limits->maxFiles());
    }

    public function testTheConfiguredSettingsAreUsed(): void
    {
        $limits = $this->limits(['uploads.max_file_size_mb' => 30, 'uploads.max_total_size_mb' => 120]);

        self::assertSame(30 * 1024 * 1024, $limits->maxFileBytes());
        self::assertSame(120 * 1024 * 1024, $limits->maxTotalBytes());
    }

    public function testPhpLimitsCapTheConfiguredOnes(): void
    {
        $limits = $this->limits(
            ['uploads.max_file_size_mb' => 50, 'uploads.max_total_size_mb' => 500],
            ['upload_max_filesize' => '2M', 'post_max_size' => '8M'],
        );

        self::assertSame(2 * 1024 * 1024, $limits->maxFileBytes());
        // post_max_size minus the room for the non-file parts of the request.
        self::assertSame(8 * 1024 * 1024 - 256 * 1024, $limits->maxTotalBytes());
    }

    public function testTheTotalIsNeverSmallerThanOneFile(): void
    {
        $limits = $this->limits(['uploads.max_file_size_mb' => 30, 'uploads.max_total_size_mb' => 10]);

        self::assertSame(30 * 1024 * 1024, $limits->maxTotalBytes());
    }

    public function testAnOversizedRequestIsDetectedFromContentLength(): void
    {
        $limits = $this->limits([], ['post_max_size' => '8M']);

        self::assertTrue($limits->isRequestTooLarge(new Request(server: ['HTTP_CONTENT_LENGTH' => (string) (9 * 1024 * 1024)])));
        self::assertFalse($limits->isRequestTooLarge(new Request(server: ['HTTP_CONTENT_LENGTH' => (string) (1024 * 1024)])));
        self::assertFalse($limits->isRequestTooLarge(new Request()));
    }

    public function testAFileRejectedByPhpIsReportedNotSkipped(): void
    {
        $limits = $this->limits();

        $errors = $limits->validate([$this->upload('grande.pdf', 10, \UPLOAD_ERR_INI_SIZE)]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('error.file_too_large', $errors[0]);
        self::assertStringContainsString('grande.pdf', $errors[0]);
    }

    public function testAPartialUploadIsReported(): void
    {
        $errors = $this->limits()->validate([$this->upload('corto.pdf', 10, \UPLOAD_ERR_PARTIAL)]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('error.partial', $errors[0]);
    }

    public function testAFileOverTheConfiguredLimitIsReported(): void
    {
        $limits = $this->limits(['uploads.max_file_size_mb' => 1]);

        $errors = $limits->validate([$this->upload('uno.txt', 10), $this->upload('dos.txt', 1024 * 1024 + 1)]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('dos.txt', $errors[0]);
    }

    public function testTheTotalOfTheFilesIsChecked(): void
    {
        $limits = $this->limits(['uploads.max_file_size_mb' => 1, 'uploads.max_total_size_mb' => 1]);
        // The total can never be below one file, so with 1 MB per file the cap is 1 MB too.
        $errors = $limits->validate([$this->upload('a.txt', 700 * 1024), $this->upload('b.txt', 700 * 1024)]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('error.total_too_large', $errors[0]);
    }

    public function testTooManyFilesAreReported(): void
    {
        $limits = $this->limits([], ['max_file_uploads' => '2']);

        $errors = $limits->validate([$this->upload('a.txt', 1), $this->upload('b.txt', 1)]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('error.too_many_files', $errors[0]);
    }

    public function testNoFileChosenIsNotAProblem(): void
    {
        $limits = $this->limits();

        self::assertSame([], $limits->validate([$this->upload('', 0, \UPLOAD_ERR_NO_FILE)]));
        self::assertNull($limits->fileProblem(null));
        self::assertNull($limits->fileProblem($this->upload('', 0, \UPLOAD_ERR_NO_FILE)));
    }

    public function testFormatSize(): void
    {
        $limits = $this->limits();

        self::assertSame('20 MB', $limits->formatSize(20 * 1024 * 1024));
        self::assertSame('1,5 MB', $limits->formatSize((int) (1.5 * 1024 * 1024)));
        self::assertSame('300 KB', $limits->formatSize(300 * 1024));
    }
}
