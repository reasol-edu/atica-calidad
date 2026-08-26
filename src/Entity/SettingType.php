<?php

declare(strict_types=1);

namespace App\Entity;

enum SettingType: string
{
    case String   = 'string';
    case Integer  = 'integer';
    case Boolean  = 'boolean';
    case Choice   = 'choice';
    case RichText = 'richtext';
    case Pdf      = 'pdf';
}
