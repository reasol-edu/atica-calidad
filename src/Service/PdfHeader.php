<?php

declare(strict_types=1);

namespace App\Service;

final readonly class PdfHeader
{
    public function __construct(
        public string $leftHtml,
        public string $rightHtml,
        public int $marginTopMm,
    ) {}
}
