<?php

declare(strict_types=1);

namespace App\Attribute;

use App\ValueResolver\CurrentCentreResolver;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Inyecta el centro educativo seleccionado en sesión como argumento del
 * controlador; si no hay centro seleccionado se redirige a la selección
 * de centro (ver CurrentCentreResolver y NoCentreSelectedSubscriber).
 *
 * Extiende ValueResolver para fijar el resolutor y evitar que el
 * EntityValueResolver de Doctrine intente cargar el centro desde la ruta.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class CurrentCentre extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(CurrentCentreResolver::class);
    }
}
