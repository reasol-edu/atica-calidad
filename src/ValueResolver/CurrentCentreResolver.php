<?php

declare(strict_types=1);

namespace App\ValueResolver;

use App\Attribute\CurrentCentre;
use App\Service\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final class CurrentCentreResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return iterable<\App\Entity\EducationalCentre>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getAttributes(CurrentCentre::class, ArgumentMetadata::IS_INSTANCEOF) === []) {
            return [];
        }

        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            throw new NoCentreSelectedException();
        }

        return [$centre];
    }
}
