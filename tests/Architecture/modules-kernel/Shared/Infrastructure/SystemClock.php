<?php

declare(strict_types=1);

namespace Modules\Shared\Infrastructure;

use Modules\Shared\Contracts\Money;

/** The kernel's implementations live in its module's Infrastructure, under the ordinary rules. */
final class SystemClock
{
    public function zero(): Money
    {
        return new Money(0, 'CDF');
    }
}
