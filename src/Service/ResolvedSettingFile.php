<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SettingFile;

/** Fichero resuelto para un ajuste de tipo pdf, junto con su nombre de fichero mostrado en la interfaz. */
final readonly class ResolvedSettingFile
{
    public function __construct(
        public SettingFile $file,
        public string $filename,
    ) {}
}
