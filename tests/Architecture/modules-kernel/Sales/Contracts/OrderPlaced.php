<?php

declare(strict_types=1);

namespace Modules\Sales\Contracts;

use Modules\Shared\Contracts\Money;

/** A cross-module event carrying the kernel's Money: allowed with a kernel. */
final readonly class OrderPlaced
{
    public function __construct(public string $orderId, public Money $total)
    {
    }
}
