<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Service\Form\Layout\Image;

/**
 * What a file of a document needs to draw its pages: the header and footer (HTML, with every `{token}` filled in but the page numbers
 * and the logos, which each output turns into its own fields), the header of the first page when it is another one, the orientation,
 * the font scale, the margins and the logos.
 */
final readonly class DocumentLayout
{
    /**
     * @param string                                              $firstHeaderHtml the header of the first page; empty when it is the normal one
     * @param array{top: int, right: int, bottom: int, left: int} $margins         in millimetres
     * @param array<string, Image>                                $images          the logos by token (`logo`, `logo2`) — only those the centre has
     */
    public function __construct(
        public string $headerHtml,
        public string $footerHtml,
        public string $firstHeaderHtml,
        public string $orientation,
        public int $fontScale,
        public array $margins,
        public array $images = [],
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

    public function hasFirstHeader(): bool
    {
        return $this->firstHeaderHtml !== '';
    }
}
