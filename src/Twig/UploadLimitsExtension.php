<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\UploadLimits;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Exposes the real upload limits (the application's, capped by PHP's) to the templates. */
final class UploadLimitsExtension extends AbstractExtension
{
    public function __construct(
        private readonly UploadLimits $limits,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('upload_limits', fn (): UploadLimits => $this->limits),
        ];
    }
}
