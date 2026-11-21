<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

final readonly class InvoiceNumber
{
    public function __construct(public string $value)
    {
    }
}
