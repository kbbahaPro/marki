<?php

namespace App\Support;

/**
 * Converts between "HH:MM" strings and minutes since local midnight.
 *
 * An interval end of "00:00" means midnight at the end of the day (1440),
 * since HTML time inputs cannot express "24:00".
 */
final class TimeOfDay
{
    public const END_OF_DAY = 1440;

    public static function toMinutes(string $time, bool $isEnd = false): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        $total = $hours * 60 + $minutes;

        return $isEnd && $total === 0 ? self::END_OF_DAY : $total;
    }

    public static function format(int $minutes): string
    {
        $minutes %= self::END_OF_DAY;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Parse submitted ["start" => "HH:MM", "end" => "HH:MM"] pairs.
     *
     * @param  array<int, array{start: string, end: string}>  $intervals
     * @return list<array{0: int, 1: int}> Sorted [start, end] minute pairs.
     */
    public static function parseIntervals(array $intervals): array
    {
        $parsed = array_map(
            fn (array $i) => [self::toMinutes($i['start']), self::toMinutes($i['end'], isEnd: true)],
            array_values($intervals),
        );

        usort($parsed, fn ($a, $b) => $a[0] <=> $b[0]);

        return $parsed;
    }

    /**
     * Return an error message if intervals are empty, inverted or overlapping.
     *
     * @param  list<array{0: int, 1: int}>  $sorted
     */
    public static function validateIntervals(array $sorted): ?string
    {
        foreach ($sorted as $index => [$start, $end]) {
            if ($end <= $start) {
                return __('Each time range must end after it starts.');
            }

            if ($index > 0 && $start < $sorted[$index - 1][1]) {
                return __('Time ranges on the same day cannot overlap.');
            }
        }

        return null;
    }
}
