<?php

declare(strict_types=1);

namespace App\Service\Form\Office;

/** The look of the DOCX and ODT files of the documents: the colours of the application (hex without '#'), the font and the sizes in points. */
final class Theme
{
    public const string GREEN      = '46603d';
    public const string GREEN_SOFT = 'eef2ea';
    public const string INK        = '2e2822';
    public const string MUTED      = '5e5044';
    public const string RULE       = 'e3d8c1';
    public const string FILL       = 'f6f1e6';
    public const string ZEBRA      = 'fbf9f3';
    public const string LINE       = '555555';

    public const string FONT = 'Calibri';

    /** The size of the text of the body at 100 %. */
    public const float BODY = 10.5;

    /** @var array<int, float> the size of the title (0) and of each level of heading at 100 % */
    public const array HEADINGS = [0 => 22.0, 1 => 16.0, 2 => 13.5, 3 => 12.0, 4 => 11.0, 5 => 10.5];

    /** The size of the running header and footer (they do not follow the font scale). */
    public const float RUNNING = 9.0;

    public static function size(float $points, float $scale): float
    {
        return round($points * $scale, 1);
    }
}
