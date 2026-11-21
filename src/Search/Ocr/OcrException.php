<?php

declare(strict_types=1);

namespace App\Search\Ocr;

/** One page or file could not be recognised (damaged, a tool timed out): the caller records it and goes on. */
class OcrException extends \RuntimeException {}
