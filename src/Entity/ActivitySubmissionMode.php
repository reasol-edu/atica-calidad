<?php

declare(strict_types=1);

namespace App\Entity;

/** How an activity is delivered: uploading a file (the default) or filling a document model online. One mode per activity. */
enum ActivitySubmissionMode: string
{
    case File = 'file';
    case Form = 'form';
}
