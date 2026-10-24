<?php

declare(strict_types=1);

namespace App\Bmi\Infrastructure;

use App\Billing\Invoice;
use App\Bmi\Domain\Patient;
use Zana\Database\ConnectionInterface;

final class PatientTable
{
    public function __construct(private ConnectionInterface $db)
    {
    }
}
