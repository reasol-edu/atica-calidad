<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/** A logo to draw in a header or footer: the bytes of a PNG or JPEG and its size in pixels. */
final readonly class Image
{
    /** The logo is drawn this tall at most, and this wide at most (centimetres): what fits in a row of a header. */
    public const float MAX_HEIGHT_CM = 1.6;
    public const float MAX_WIDTH_CM  = 5.0;

    public function __construct(
        public string $content,
        /** @var 'image/png'|'image/jpeg' */
        public string $mime,
        public int $width,
        public int $height,
    ) {}

    public static function fromBytes(string $content): ?self
    {
        $info = @getimagesizefromstring($content);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        return match ($info['mime']) {
            'image/png'  => new self($content, 'image/png', $info[0], $info[1]),
            'image/jpeg' => new self($content, 'image/jpeg', $info[0], $info[1]),
            default      => null,
        };
    }

    public function extension(): string
    {
        return $this->mime === 'image/png' ? 'png' : 'jpg';
    }

    /**
     * The size it is drawn at, keeping its proportions and fitting the limits — and the space it has: a logo in a narrow cell shrinks to
     * the width of the cell instead of overflowing it.
     *
     * @param ?float $availableCm the width of the cell (or of the page) it goes in, when it is known
     *
     * @return array{0: float, 1: float} width and height in centimetres
     */
    public function sizeCm(?float $availableCm = null): array
    {
        $maxWidth = $availableCm === null ? self::MAX_WIDTH_CM : max(0.3, min(self::MAX_WIDTH_CM, $availableCm));
        $scale    = min(self::MAX_HEIGHT_CM / $this->height, $maxWidth / $this->width);

        return [round($this->width * $scale, 3), round($this->height * $scale, 3)];
    }
}
