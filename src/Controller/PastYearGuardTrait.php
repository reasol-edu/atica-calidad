<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;

/**
 * Requiere que la clase extienda AbstractController e inyecte
 * App\Service\TenantContext como propiedad $tenantContext.
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
