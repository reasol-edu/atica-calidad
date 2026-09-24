<?php

declare(strict_types=1);

namespace App\Entity;

/** How an indicator's value stands against its year's target (IndicatorTarget::statusOf()), or that it's missing. */
enum IndicatorStatus: string
{
    case OnTarget = 'on_target';
    case Alert = 'alert';
    case OffTarget = 'off_target';
    case NoData = 'no_data';
}
