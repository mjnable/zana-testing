<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

use Zana\Ddd\ValueObject;

/** A kernel value: may use Zana\Ddd. */
final readonly class Money extends ValueObject
{
    public function __construct(public int $minor, public string $currency)
    {
    }
}
