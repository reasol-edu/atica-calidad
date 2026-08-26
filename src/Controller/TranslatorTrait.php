<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Requiere que la clase inyecte Symfony\Contracts\Translation\TranslatorInterface
 * como propiedad $translator.
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
