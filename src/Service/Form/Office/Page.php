<?php

declare(strict_types=1);

namespace App\Service\Form\Office;

/** The paper of an office file: A4, portrait or landscape, with the margins the documents of the centre use. */
final readonly class Page
{
    public const float MARGIN_X = 2.0;
    public const float MARGIN_TOP = 2.4;
    public const float MARGIN_BOTTOM = 2.0;

    public function __construct(public bool $landscape) {}

    public function widthCm(): float
    {
        return $this->landscape ? 29.7 : 21.0;
    }

    public function heightCm(): float
    {
        return $this->landscape ? 21.0 : 29.7;
    }

    /** The width of the text: the page less both side margins. */
    public function contentCm(): float
    {
        return $this->widthCm() - 2 * self::MARGIN_X;
    }

    public static function twips(float $cm): int
    {
        return (int) round($cm * 567);
    }
}
