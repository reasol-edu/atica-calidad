<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The margins of a PDF page, in millimetres: the sides, the bottom (where the body stops, above the footer) and how far from the edge
 * of the paper the header and the footer are drawn. The top margin (where the body starts) comes from the header: see PdfHeader.
 */
final readonly class PdfMargins
{
    public function __construct(
        public float $left = 15,
        public float $right = 15,
        public float $bottom = 18,
        public float $header = 8,
        public float $footer = 8,
    ) {}
}
