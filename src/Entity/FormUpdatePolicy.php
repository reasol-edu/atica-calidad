<?php

declare(strict_types=1);

namespace App\Entity;

/** What a new revision of a model asks of the documents already filled with an earlier one: that they may update, or that they must (by a date). */
enum FormUpdatePolicy: string
{
    case Optional  = 'optional';
    case Mandatory = 'mandatory';
}
