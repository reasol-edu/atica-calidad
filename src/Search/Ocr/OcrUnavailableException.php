<?php

declare(strict_types=1);

namespace App\Search\Ocr;

/** The OCR tools are not installed (or were removed): nothing is wrong with the file, so the work waits instead of failing. */
final class OcrUnavailableException extends OcrException {}
