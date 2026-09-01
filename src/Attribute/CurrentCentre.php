<?php

declare(strict_types=1);

namespace App\Attribute;

use App\ValueResolver\CurrentCentreResolver;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Injects the educational centre selected in session as a controller
 * argument; if no centre is selected, redirects to centre selection
 * (see CurrentCentreResolver and NoCentreSelectedSubscriber).
 *
 * Extends ValueResolver to pin the resolver and prevent Doctrine's
 * EntityValueResolver from trying to load the centre from the route.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class CurrentCentre extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(CurrentCentreResolver::class);
    }
}
