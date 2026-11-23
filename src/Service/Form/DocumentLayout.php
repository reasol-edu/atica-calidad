<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * What a file of a document needs to draw its pages: the header and footer (HTML, with every `{token}` filled in but the two page
 * numbers, which each output turns into its own page fields), the orientation and the font scale.
 */
final readonly class DocumentLayout
{
    public function __construct(
        public string $headerHtml,
        public string $footerHtml,
        public string $orientation,
        public int $fontScale,
    ) {}

    public function isLandscape(): bool
    {
        return $this->orientation === FormLayout::LANDSCAPE;
    }

    /** The font scale as a factor (1.0 is the normal size). */
    public function factor(): float
    {
        return $this->fontScale / 100;
    }
}
