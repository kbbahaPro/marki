<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A half-open time range [start, end), always held in UTC.
 */
final class Period
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public function __construct(DateTimeInterface|string $start, DateTimeInterface|string $end)
    {
        $this->start = CarbonImmutable::parse($start)->utc();
        $this->end = CarbonImmutable::parse($end)->utc();

        if ($this->end < $this->start) {
            throw new InvalidArgumentException('A period cannot end before it starts.');
        }
    }

    public function overlaps(Period $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }
}
