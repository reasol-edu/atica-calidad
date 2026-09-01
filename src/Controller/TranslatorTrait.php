<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Requires the class to inject Symfony\Contracts\Translation\TranslatorInterface
 * as the $translator property.
 */
trait TranslatorTrait
{
    protected function t(string $key): string
    {
        return $this->translator->trans($key, [], $this->translationDomain());
    }

    private function translationDomain(): string
    {
        return 'admin';
    }
}
