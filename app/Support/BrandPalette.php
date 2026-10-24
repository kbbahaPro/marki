<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Turns the two configured brand colours into the full set of colour
 * tokens the UI uses, for light and dark mode, while keeping WCAG AA
 * contrast: if a brand colour is too light for white button text, or too
 * faint as link text, it is darkened (or lightened in dark mode) along the
 * same hue until it passes.
 *
 * - primary: actions, links, selected states.
 * - marker:  "the chosen thing" (selected date, your time, next meeting),
 *            always paired with dark text.
 */
final class BrandPalette
{
    /** Surfaces the colours must stay readable on (see resources/css/app.css). */
    private const LIGHT_SURFACE = [255, 255, 255];

    private const DARK_SURFACE = [17, 24, 39];

    private const DARK_CANVAS = [9, 13, 24];

    private const MARKER_INK = [18, 35, 61];

    /** @var array{0: int, 1: int, 2: int} */
    public readonly array $primary;

    /** @var array{0: int, 1: int, 2: int} */
    public readonly array $marker;

    public function __construct(string $primary, string $marker)
    {
        // Buttons put white text on the primary colour.
        $this->primary = self::darkenUntil(self::parse($primary), fn ($c) => self::contrast($c, [255, 255, 255]) >= 4.5);
        $this->marker = self::parse($marker);
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('booking.brand.primary'),
            (string) config('booking.brand.marker'),
        );
    }

    /**
     * Token values (as "r g b" triplets) for both themes.
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public function tokens(): array
    {
        $light = [
            'primary' => $this->primary,
            'primary-hover' => self::mix($this->primary, [0, 0, 0], 0.15),
            'primary-ink' => self::darkenUntil($this->primary, fn ($c) => self::contrast($c, self::LIGHT_SURFACE) >= 4.5 && self::contrast($c, self::mix($this->primary, self::LIGHT_SURFACE, 0.92)) >= 4.5),
            'primary-soft' => self::mix($this->primary, self::LIGHT_SURFACE, 0.92),
            'marker' => $this->marker,
            'marker-ink' => self::MARKER_INK,
        ];

        $darkSoft = self::mix($this->primary, self::DARK_CANVAS, 0.72);

        $dark = [
            'primary' => $this->primary,
            'primary-hover' => self::mix($this->primary, [255, 255, 255], 0.12),
            'primary-ink' => self::lightenUntil($this->primary, fn ($c) => self::contrast($c, self::DARK_SURFACE) >= 4.5 && self::contrast($c, $darkSoft) >= 4.5),
            'primary-soft' => $darkSoft,
            'marker' => $this->marker,
            'marker-ink' => self::MARKER_INK,
        ];

        return [
            'light' => array_map(self::triplet(...), $light),
            'dark' => array_map(self::triplet(...), $dark),
        ];
    }

    /**
     * A ready-to-inline <style> body overriding the default tokens.
     */
    public function css(): string
    {
        $declarations = fn (array $tokens) => implode('', array_map(
            fn ($name, $value) => "--{$name}:{$value};",
            array_keys($tokens),
            $tokens,
        ));

        $tokens = $this->tokens();

        return ':root{'.$declarations($tokens['light']).'}'
            .'@media (prefers-color-scheme: dark){:root{'.$declarations($tokens['dark']).'}}';
    }

    /** Hex of the (contrast-safe) primary, for inline styles in emails. */
    public function primaryHex(): string
    {
        return self::hex($this->primary);
    }

    public function markerHex(): string
    {
        return self::hex($this->marker);
    }

    /**
     * WCAG 2.x contrast ratio between two colours.
     *
     * @param  array{0: int, 1: int, 2: int}  $a
     * @param  array{0: int, 1: int, 2: int}  $b
     */
    public static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function parse(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            throw new InvalidArgumentException("Brand colours must be hex values like #1D5FAF, got \"{$hex}\".");
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $color
     */
    private static function luminance(array $color): float
    {
        $channel = function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($color[0]) + 0.7152 * $channel($color[1]) + 0.0722 * $channel($color[2]);
    }

    /**
     * Blend $color towards $with by $amount (0 = unchanged, 1 = $with).
     *
     * @param  array{0: int, 1: int, 2: int}  $color
     * @param  array{0: int, 1: int, 2: int}  $with
     * @return array{0: int, 1: int, 2: int}
     */
    private static function mix(array $color, array $with, float $amount): array
    {
        return array_map(fn ($c, $w) => (int) round($c + ($w - $c) * $amount), $color, $with);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $color
     * @return array{0: int, 1: int, 2: int}
     */
    private static function darkenUntil(array $color, callable $passes): array
    {
        for ($step = 0; $step < 20 && ! $passes($color); $step++) {
            $color = self::mix($color, [0, 0, 0], 0.08);
        }

        return $color;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $color
     * @return array{0: int, 1: int, 2: int}
     */
    private static function lightenUntil(array $color, callable $passes): array
    {
        for ($step = 0; $step < 20 && ! $passes($color); $step++) {
            $color = self::mix($color, [255, 255, 255], 0.08);
        }

        return $color;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $color
     */
    private static function triplet(array $color): string
    {
        return implode(' ', $color);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $color
     */
    private static function hex(array $color): string
    {
        return sprintf('#%02X%02X%02X', ...$color);
    }
}
