<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

use Modules\Sales\Contracts\OrderPlaced;
use Psr\Log\LoggerInterface;

/** What a kernel must not do: use a module, or a service. */
final class Leaky
{
    public function __construct(public OrderPlaced $event, public LoggerInterface $logger)
    {
    }
}
