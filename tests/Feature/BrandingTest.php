<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmedGuest;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'Marki', 'booking.brand.wordmark' => 'marki']);
    }

    public function test_pages_carry_the_mark_wordmark_favicons_and_brand_colours(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<title>Marki</title>', escape: false)
            ->assertSee('>marki<', escape: false)
            ->assertSee('Mark that time.')
            ->assertSee(asset('favicon.svg'))
            ->assertSee(asset('apple-touch-icon.png'))
            ->assertSee(':root{--primary:29 95 175;', escape: false);
    }

    public function test_brand_colours_and_icons_come_from_configuration(): void
    {
        config([
            'booking.brand.primary' => '#005F57',
            'booking.brand.favicon_url' => 'https://cdn.example.com/favicon.png',
            'booking.brand.logo_url' => 'https://cdn.example.com/logo.svg',
            'booking.brand.tagline' => null,
        ]);

        $this->get('/login')
            ->assertSee(':root{--primary:0 95 87;', escape: false)
            ->assertSee('https://cdn.example.com/favicon.png')
            ->assertDontSee(asset('favicon.svg'))
            ->assertSee('https://cdn.example.com/logo.svg')
            ->assertDontSee('>marki<', escape: false)
            ->assertDontSee('Mark that time.');
    }

    public function test_emails_use_the_brand_mark_and_colour(): void
    {
        config(['booking.brand.primary' => '#005F57']);
        $eventType = EventType::factory()->for(User::factory())->create();
        $booking = Booking::factory()->at($eventType, '2030-01-07 10:00')->create(['meeting_url' => 'https://meet.google.com/x']);

        $html = (new BookingConfirmedGuest($booking, str_repeat('t', 64)))->render();

        $this->assertStringContainsString(asset('brand/mark-128.png'), $html);
        $this->assertStringContainsString('>marki<', $html);
        $this->assertStringContainsString('background:#005F57', $html);
        $this->assertStringNotContainsString('#2563eb', $html);
    }

    public function test_icon_files_exist(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png', 'brand/mark-128.png', 'brand/mark-512.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }

        $this->assertSame(3, unpack('vreserved/vtype/vcount', file_get_contents(public_path('favicon.ico')))['count']);
    }
}
