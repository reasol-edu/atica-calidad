<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;

interface TenantContextInterface
{
    public function getSelectedCentre(): ?EducationalCentre;

    public function getViewYear(EducationalCentre $centre): ?AcademicYear;

    public function isViewingNonActiveYear(EducationalCentre $centre): bool;
}
