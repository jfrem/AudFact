<?php

declare(strict_types=1);

namespace App\Services\Audit;

/** Origen del plazo resuelto; no se serializa en los contratos HTTP o Redis. */
enum DeliveryValiditySource
{
    case VISUAL;
    case CLIENT_CONFIG;
    case SYSTEM_DEFAULT;
}
