<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SettingFile;

/** Resolved file for a pdf-type setting, along with its filename as shown in the UI. */
final readonly class ResolvedSettingFile
{
    public function __construct(
        public SettingFile $file,
        public string $filename,
    ) {}
}
