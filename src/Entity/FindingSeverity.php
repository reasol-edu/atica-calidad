<?php

declare(strict_types=1);

namespace App\Entity;

/** How serious a nonconformity is. */
enum FindingSeverity: string
{
    case Minor = 'minor';
    case Major = 'major';
}
