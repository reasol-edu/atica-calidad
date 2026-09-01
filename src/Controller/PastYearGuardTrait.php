<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;

/**
 * Requires the class to extend AbstractController and inject
 * App\Service\TenantContext as the $tenantContext property.
 */
trait PastYearGuardTrait
{
    private function denyIfViewingPastYear(EducationalCentre $centre): void
    {
        if ($this->tenantContext->isViewingNonActiveYear($centre)) {
            throw $this->createAccessDeniedException('Write operations are not allowed while viewing a non-active academic year.');
        }
    }
}
