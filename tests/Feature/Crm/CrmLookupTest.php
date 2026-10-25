<?php

namespace Tests\Feature\Crm;

use App\Enums\SyncStatus;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The "existing customer" badge on a host's booking page, from the CRM's
 * GET /api/v1/contacts/lookup.
 */
class CrmLookupTest extends TestCase
{
    use RefreshDatabase;

    private const LOOKUP = 'https://crm.test/api/v1/contacts/lookup*';

    private User $host;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Http::preventStrayRequests();
        config(['services.crm.api_url' => 'https://crm.test/', 'services.crm.api_key' => 'crm_lookupkey']);

        $this->host = User::factory()->create();
        $eventType = EventType::factory()->for($this->host)->create();
        $this->booking = Booking::factory()->at($eventType, '2026-11-09 09:00')
            ->create(['guest_email' => 'Jane@Acme.example', 'sync_status' => SyncStatus::Skipped]);
    }

    public function test_an_existing_contact_shows_the_badge_owner_and_deals(): void
    {
        Http::fake([self::LOOKUP => Http::response(['found' => true, 'contact' => [
            'id' => 7, 'name' => 'Jane Doe', 'email' => 'jane@acme.example', 'owner_name' => 'Sam Seller',
            'companies' => ['Acme'], 'open_deals_count' => 2, 'last_meeting_at' => null,
            'crm_url' => 'https://crm.test/contacts/7',
        ]])]);

        $this->show()
            ->assertSee('Existing customer')
            ->assertSee('Owner: Sam Seller')
            ->assertSee('Acme')
            ->assertSee('2 open deals')
            ->assertSee('href="https://crm.test/contacts/7"', false);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://crm.test/api/v1/contacts/lookup?email=jane%40acme.example'
            && $request->hasHeader('Authorization', 'Bearer crm_lookupkey'));
    }

    public function test_an_unknown_guest_shows_no_badge(): void
    {
        Http::fake([self::LOOKUP => Http::response(['found' => false])]);

        $this->show()->assertDontSee('Existing customer');
    }

    public function test_an_unreachable_crm_fails_quietly_and_is_not_asked_again_at_once(): void
    {
        $attempts = 0;
        Http::fake([self::LOOKUP => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timed out');
        }]);

        $this->show()->assertDontSee('Existing customer');
        $this->show()->assertDontSee('Existing customer');
        $this->assertSame(1, $attempts);

        $this->travel(2)->minutes();
        $this->show();
        $this->assertSame(2, $attempts);
    }

    public function test_an_error_response_fails_quietly(): void
    {
        Http::fake([self::LOOKUP => Http::response(['error' => ['code' => 'forbidden']], 403)]);

        $this->show()->assertDontSee('Existing customer');
    }

    public function test_results_are_cached(): void
    {
        Http::fake([self::LOOKUP => Http::response(['found' => true, 'contact' => ['name' => 'Jane', 'open_deals_count' => 0]])]);

        $this->show()->assertSee('No open deals');
        $this->show()->assertSee('Existing customer');
        Http::assertSentCount(1);

        $this->travel(11)->minutes();
        $this->show();
        Http::assertSentCount(2);
    }

    public function test_only_web_links_to_the_crm_are_rendered(): void
    {
        Http::fake([self::LOOKUP => Http::response(['found' => true, 'contact' => ['crm_url' => 'javascript:alert(1)']])]);

        $this->show()->assertSee('Existing customer')->assertDontSee('javascript:alert(1)')->assertDontSee('Open in CRM');
    }

    public function test_no_lookup_without_a_key(): void
    {
        config(['services.crm.api_key' => null]);
        Http::fake();

        $this->show()->assertDontSee('Existing customer');
        Http::assertNothingSent();
    }

    private function show(): TestResponse
    {
        return $this->actingAs($this->host)->get("/dashboard/bookings/{$this->booking->uid}")->assertOk();
    }
}
