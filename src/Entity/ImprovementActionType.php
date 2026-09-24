<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Repair: fix the immediate effect (the standard's "correction"). Corrective: remove the cause so
 * it doesn't happen again. Preventive: avoid a problem that hasn't happened yet. Improvement: make
 * something that already works better.
 */
enum ImprovementActionType: string
{
    case Repair = 'repair';
    case Corrective = 'corrective';
    case Preventive = 'preventive';
    case Improvement = 'improvement';
}
