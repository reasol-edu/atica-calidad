<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Which Séneca CSV export a Responsabilidades › Listas import is building a list-item tree from.
 * Not persisted anywhere — purely a routing/service discriminator between the two CSV shapes.
 */
enum SenecaListImportKind: string
{
    case Groups   = 'groups';
    case Subjects = 'subjects';
}
