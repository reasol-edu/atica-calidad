<?php

declare(strict_types=1);

namespace App\Service\Form\Office;

/**
 * The paper of an office file: A4, portrait or landscape, with the margins of the layout: the sides, and how far from the edge of the
 * paper the header (top) and the footer (bottom) are. The body goes below the header and above the footer, whatever their height.
 */
final readonly class Page
{
    /** @param array{top: int, right: int, bottom: int, left: int} $margins in millimetres */
    public function __construct(
        public bool $landscape,
        public array $margins = ['top' => 10, 'right' => 15, 'bottom' => 10, 'left' => 15],
    ) {}

    public function widthCm(): float
    {
        return $this->landscape ? 29.7 : 21.0;
    }

    public function heightCm(): float
    {
        return $this->landscape ? 21.0 : 29.7;
    }

    public function topCm(): float
    {
        return $this->margins['top'] / 10;
    }

    public function bottomCm(): float
    {
        return $this->margins['bottom'] / 10;
    }

    public function leftCm(): float
    {
        return $this->margins['left'] / 10;
    }

    public function rightCm(): float
    {
        return $this->margins['right'] / 10;
    }

    /** The width of the text: the page less both side margins. */
    public function contentCm(): float
    {
        return $this->widthCm() - $this->leftCm() - $this->rightCm();
    }

    public static function twips(float $cm): int
    {
        return (int) round($cm * 567);
    }
}
