<?php

declare(strict_types=1);

namespace App\Model;

/**
 * How a Séneca subjects import lays out its list: by group («1.º ESO A › Matemáticas», the original one) or by subject
 * («Matemáticas › 1.º ESO A»: associating each subject with its department once is enough, all its groups inherit it). Not
 * persisted: the import detects the one a list already has (SenecaListImporter::detectLayout()).
 */
enum SenecaSubjectsLayout: string
{
    case ByGroup   = 'group';
    case BySubject = 'subject';
}
