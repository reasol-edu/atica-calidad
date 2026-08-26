<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class NationalIdExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('mask_national_id', $this->maskNationalId(...)),
        ];
    }

    /**
     * Masks a Spanish national ID following AEPD guidelines.
     *
     * Rules (digit positions are 1-indexed, skipping alphabetic characters):
     * - DNI  (12345678X)   → shows digit positions 4–7 → ***4567**
     * - NIE  (L1234567X)   → shows digit positions 4–7 (skipping leading letter) → ****4567*
     * - Passport (ABC123456, exactly 6 digits) → shows digit positions 3–6 → *****3456
     * - Other (≥7 digits)  → shows digit positions 4–7 → *****4567***
     */
    public function maskNationalId(string $id): string
    {
        $id         = strtoupper(trim($id));
        $digitCount = preg_match_all('/\d/', $id) ?: 0;

        if (preg_match('/^\d{8}[A-Z]$/', $id)) {
            $digitStart = 4; // DNI
        } elseif (preg_match('/^[XYZ]\d{7}[A-Z]$/', $id)) {
            $digitStart = 4; // NIE
        } elseif ($digitCount === 6) {
            $digitStart = 3; // Passport (only 6 digits)
        } elseif ($digitCount >= 7) {
            $digitStart = 4; // Other identification with ≥7 digits
        } else {
            return str_repeat('*', \strlen($id));
        }

        $result   = '';
        $digitIdx = 0;
        for ($i = 0, $len = \strlen($id); $i < $len; $i++) {
            if (ctype_digit($id[$i])) {
                $digitIdx++;
                $result .= ($digitIdx >= $digitStart && $digitIdx <= $digitStart + 3) ? $id[$i] : '*';
            } else {
                $result .= '*';
            }
        }

        return $result;
    }
}
