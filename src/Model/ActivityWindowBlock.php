<?php

declare(strict_types=1);

namespace App\Model;

/** Why an activity's enforced start/end date currently blocks submissions and manual completion. */
enum ActivityWindowBlock: string
{
    case BeforeStart = 'before_start';
    case AfterEnd    = 'after_end';
}
