<?php

namespace Tests\Unit;

use App\Support\BrandPalette;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrandPaletteTest extends TestCase
{
    private const WHITE = [255, 255, 255];

    private const DARK_SURFACE = [17, 24, 39];

    public function test_marki_colours_are_used_unchanged(): void
    {
        $palette = new BrandPalette('#1D5FAF', '#F4A93C');

        $this->assertSame('#1D5FAF', $palette->primaryHex());
        $this->assertSame('#F4A93C', $palette->markerHex());
        $this->assertSame('29 95 175', $palette->tokens()['light']['primary']);
        $this->assertSame('244 169 60', $palette->tokens()['light']['marker']);
    }

    public function test_a_too_light_primary_is_darkened_until_white_text_is_readable(): void
    {
        $palette = new BrandPalette('#FFD400', '#F4A93C');

        $this->assertNotSame('#FFD400', $palette->primaryHex());
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($palette->primary, self::WHITE));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function brandColours(): array
    {
        return [
            'marki blue' => ['#1D5FAF'],
            'tplus teal' => ['#005F57'],
            'bright yellow' => ['#FFD400'],
            'pale pink' => ['#FBCFE8'],
            'near black' => ['#111111'],
            'pure red' => ['#FF0000'],
        ];
    }

    #[DataProvider('brandColours')]
    public function test_link_text_stays_readable_in_both_themes(string $hex): void
    {
        $tokens = (new BrandPalette($hex, '#F4A93C'))->tokens();
        $rgb = fn (string $triplet) => array_map('intval', explode(' ', $triplet));

        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($rgb($tokens['light']['primary-ink']), self::WHITE), 'light link on white');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($rgb($tokens['light']['primary-ink']), $rgb($tokens['light']['primary-soft'])), 'light link on soft tint');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($rgb($tokens['dark']['primary-ink']), self::DARK_SURFACE), 'dark link on surface');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($rgb($tokens['dark']['primary-ink']), $rgb($tokens['dark']['primary-soft'])), 'dark link on soft tint');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($rgb($tokens['light']['primary']), self::WHITE), 'white button text');
    }

    public function test_marker_text_is_readable_on_the_marker(): void
    {
        $tokens = (new BrandPalette('#1D5FAF', '#F4A93C'))->tokens();
        $rgb = fn (string $triplet) => array_map('intval', explode(' ', $triplet));

        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($rgb($tokens['light']['marker']), $rgb($tokens['light']['marker-ink'])));
    }

    public function test_css_overrides_both_themes(): void
    {
        $css = (new BrandPalette('#005F57', '#F4A93C'))->css();

        $this->assertStringStartsWith(':root{--primary:0 95 87;', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark){:root{--primary:0 95 87;', $css);
        $this->assertStringContainsString('--marker:244 169 60;', $css);
    }

    public function test_short_hex_and_missing_hash_are_accepted(): void
    {
        $this->assertSame([255, 0, 0], BrandPalette::parse('f00'));
        $this->assertSame([29, 95, 175], BrandPalette::parse('1d5faf'));
    }

    public function test_invalid_colours_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BrandPalette('blue', '#F4A93C');
    }
}
