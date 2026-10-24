<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Bookable start times of a team event type, with the round-robin hosts
 * free at each one (always empty for collective events, where everyone attends).
 */
final class TeamSlots
{
    /**
     * @param  array<int, list<int>>  $rotatingHosts  UTC timestamp => ids of free rotating hosts, ascending by time.
     */
    public function __construct(public readonly array $rotatingHosts = []) {}

    /**
     * @return list<CarbonImmutable>
     */
    public function starts(): array
    {
        return array_map(fn (int $ts) => CarbonImmutable::createFromTimestampUTC($ts), array_keys($this->rotatingHosts));
    }

    /**
     * Rotating hosts free at $start, or null when $start is not bookable.
     *
     * @return list<int>|null
     */
    public function rotatingHostsAt(CarbonImmutable $start): ?array
    {
        return $this->rotatingHosts[$start->getTimestamp()] ?? null;
    }
}
