<?php

declare(strict_types=1);

namespace App\Service;

enum PdfTemplateValidationError
{
    case InvalidPdf;
    case MultiPage;
    case WrongOrientation;
}
