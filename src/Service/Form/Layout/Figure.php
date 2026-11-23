<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/**
 * A picture in the body of an office document: an attached image of a field «Adjuntos», drawn under the field with its caption
 * («Anexo 1: foto.png»), as the PDF and the web view show it.
 */
final readonly class Figure
{
    /** The largest it is drawn (centimetres): the same box as in the PDF. */
    public const float MAX_WIDTH_CM  = 9.0;
    public const float MAX_HEIGHT_CM = 7.0;

    public function __construct(
        public Image $image,
        public string $caption,
        /** A name for the file inside the package, unique in the document (e.g. "anexo-1"). */
        public string $name,
    ) {}

    /**
     * The size it is drawn at: its own (at 96 pixels per inch) when smaller than the box, fitted to the box and to the room keeping its
     * proportions when bigger.
     *
     * @return array{0: float, 1: float} width and height in centimetres
     */
    public function sizeCm(float $roomCm): array
    {
        $width  = $this->image->width / 96 * 2.54;
        $height = $this->image->height / 96 * 2.54;
        $scale  = min(1.0, min(self::MAX_WIDTH_CM, $roomCm) / $width, self::MAX_HEIGHT_CM / $height);

        return [round($width * $scale, 3), round($height * $scale, 3)];
    }
}
