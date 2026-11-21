<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

use Modules\Billing\Contracts\InvoiceNumber;
use Modules\Shared\Contracts\Money;

/** A domain on the kernel's Money (allowed with a kernel), and on another module's Contracts (never allowed). */
final class Order
{
    public function __construct(public Money $total, public ?InvoiceNumber $invoice = null)
    {
    }
}
