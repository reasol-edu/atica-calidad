<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class FileSizeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('file_size', $this->formatFileSize(...)),
        ];
    }

    public function formatFileSize(int $bytes): string
    {
        $units    = ['B', 'KiB', 'MiB', 'GiB'];
        $exponent = $bytes > 0 ? min((int) floor(log($bytes, 1024)), \count($units) - 1) : 0;
        $value    = $bytes / (1024 ** $exponent);
        $decimals = $exponent === 0 ? 0 : 1;

        return number_format($value, $decimals, ',', '.') . ' ' . $units[$exponent];
    }
}
