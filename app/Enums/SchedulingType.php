<?php

namespace App\Enums;

enum SchedulingType: string
{
    /** A personal event type with one host. */
    case Single = 'single';

    /** One host from the team's pool attends (plus any fixed hosts). */
    case RoundRobin = 'round_robin';

    /** Every host attends. */
    case Collective = 'collective';

    public function label(): string
    {
        return match ($this) {
            self::Single => __('One-on-one'),
            self::RoundRobin => __('Round robin'),
            self::Collective => __('Collective'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Single => __('Meetings with you.'),
            self::RoundRobin => __('Each booking goes to one available host, shared out fairly.'),
            self::Collective => __('Every host attends; only times when all of them are free are offered.'),
        };
    }

    /**
     * @return list<self>
     */
    public static function teamCases(): array
    {
        return [self::RoundRobin, self::Collective];
    }
}
