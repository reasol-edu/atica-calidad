<?php

declare(strict_types=1);

namespace App\Service;

enum SettingsSaveOutcome
{
    case Saved;
    case RejectedLocked;
    case RejectedInvalid;
}
