<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Who owns each expected submission slot of an activity (see ActivitySubmissionSlotBuilder):
 * either every teacher holding an upload profile/subperfil of the activity's folder must submit
 * their own, or a single submission is shared by everyone holding that profile/subperfil.
 */
enum ActivitySubmissionScope: string
{
    case Individual = 'individual';
    case ByProfile  = 'by_profile';
}
