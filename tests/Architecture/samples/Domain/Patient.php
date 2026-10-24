<?php

declare(strict_types=1);

namespace App\Bmi\Domain;

use App\Bmi\Domain\Bmi;
use App\Bmi\Infrastructure\{PatientTable, Mapper as RowMapper};
use DateTimeImmutable;
use Zana\Ddd\Domain\Entity, Zana\Ddd\Domain\Identity;
use function App\Shared\format_bmi;

/**
 * Mentions \App\Billing\Invoice only in a comment, which is ignored.
 */
final class Patient extends Entity
{
    use \App\Bmi\Domain\Concerns\Auditable;

    public function save(int $x): void
    {
        $callback = function () use ($x): string {
            return "\\App\\Billing\\NotAName {$x}";
        };
        new \App\Bmi\Infrastructure\Database();
        \strlen('x');
        $at = new \DateTimeImmutable();
    }
}
