<?php

namespace Tests\Feature\Crm;

use App\Enums\SchedulingType;
use App\Jobs\SendCrmWebhook;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\GuestDetails;
use App\Services\Crm\BookingPayload;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Booking webhooks to the CRM, checked against the contract's sample bodies
 * in tests/Fixtures/crm-webhooks. The host works Mon–Fri 09:00–17:00 UTC;
 * "now" is Sunday 2026-11-01 10:00 UTC.
 */
class BookingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://crm.test/api/v1/integrations/calendar/webhook';

    private const SECRET = 'test-webhook-secret';

    private User $host;

    private EventType $eventType;

    /** @var \Closure(): mixed */
    private \Closure $respond;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Mail::fake();
        // Tests swap the CRM's answer by setting $this->respond.
        Http::preventStrayRequests();
        Http::fake([self::URL => fn () => ($this->respond)()]);
        $this->respond = fn () => Http::response(['status' => 'accepted'], 202);
        config([
            'services.crm.install_id' => '01JC00000000000000INSTALL0',
            'services.crm.webhook_url' => self::URL,
            'services.crm.webhook_secret' => self::SECRET,
        ]);

        $this->host = User::factory()->create(['username' => 'jane', 'name' => 'Jane Host', 'email' => 'jane@host.test']);
        $this->eventType = EventType::factory()->for($this->host)->create(['title' => 'Intro call', 'slug' => 'intro', 'duration_minutes' => 30]);
    }

    // --- Events ---------------------------------------------------------

    public function test_a_new_booking_sends_booking_created(): void
    {
        $question = $this->eventType->questions()->create(['label' => 'Company size', 'type' => 'select', 'options' => ['1-10', '11-50'], 'is_required' => true, 'position' => 0]);

        $this->post('/jane/intro', [
            'start' => '2026-11-09T09:00:00Z',
            'timezone' => 'Africa/Tunis',
            'name' => 'Jane Doe',
            'email' => 'jane.doe@acme.example',
            'notes' => 'Looking forward to it.',
            'answers' => [$question->id => '11-50'],
        ])->assertRedirect();

        $booking = Booking::sole();
        $body = $this->sentBody('booking.created');

        $this->assertMatchesContract('booking.created', $body);
        $this->assertSame('01JC00000000000000INSTALL0', $body['organization_id']);
        $this->assertSame(1, $body['version']);
        $this->assertSame('2026-11-01T10:00:00+00:00', $body['occurred_at']);
        $this->assertSame([
            'id' => $booking->uid,
            'previous_booking_id' => null,
            'status' => 'confirmed',
            'start_at' => '2026-11-09T09:00:00+00:00',
            'end_at' => '2026-11-09T09:30:00+00:00',
            'timezone' => 'Africa/Tunis',
            'location' => ['type' => 'google_meet', 'value' => null, 'url' => null],
            'manage_url' => null,
            'event_type' => ['id' => $this->eventType->id, 'slug' => 'intro', 'name' => 'Intro call', 'kind' => 'personal', 'team_slug' => null],
            'organizer_email' => 'jane@host.test',
            'hosts' => [['email' => 'jane@host.test', 'name' => 'Jane Host', 'is_organizer' => true]],
            'invitee' => ['name' => 'Jane Doe', 'email' => 'jane.doe@acme.example', 'phone' => null, 'notes' => 'Looking forward to it.'],
            'answers' => [['question_id' => $question->id, 'label' => 'Company size', 'type' => 'select', 'value' => '11-50']],
            'cancellation' => null,
        ], $body['booking']);
    }

    public function test_requests_are_signed_over_the_exact_body(): void
    {
        $this->book('2026-11-09T09:00Z');

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-Marki-Timestamp')[0];
            $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET);

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->header('X-Marki-Event')[0] === 'booking.created'
                && $request->header('X-Marki-Delivery')[0] === json_decode($request->body(), true)['id']
                && $timestamp === (string) now()->getTimestamp()
                && hash_equals($expected, $request->header('X-Marki-Signature')[0]);
        });
    }

    public function test_a_reschedule_sends_the_new_booking_with_the_previous_id(): void
    {
        $previous = $this->book('2026-11-09T09:00Z');

        [$booking] = app(BookingService::class)->reschedule($previous, CarbonImmutable::parse('2026-11-10T09:00Z'));

        $body = $this->sentBody('booking.rescheduled');
        $this->assertMatchesContract('booking.rescheduled', $body);
        $this->assertSame($booking->uid, $body['booking']['id']);
        $this->assertSame($previous->uid, $body['booking']['previous_booking_id']);
        $this->assertSame('2026-11-10T09:00:00+00:00', $body['booking']['start_at']);
        $this->assertSame('confirmed', $body['booking']['status']);
    }

    public function test_a_cancellation_carries_the_reason_and_who_cancelled(): void
    {
        $booking = $this->book('2026-11-09T09:00Z');
        $this->travel(5)->minutes();

        app(BookingService::class)->cancel($booking, 'guest', 'Something came up.');

        $body = $this->sentBody('booking.cancelled');
        $this->assertMatchesContract('booking.cancelled', $body);
        $this->assertSame('cancelled', $body['booking']['status']);
        $this->assertSame([
            'reason' => 'Something came up.',
            'cancelled_by' => 'guest',
            'cancelled_at' => '2026-11-01T10:05:00+00:00',
        ], $body['booking']['cancellation']);
        $this->assertSame('2026-11-01T10:05:00+00:00', $body['occurred_at']);
    }

    public function test_marking_a_no_show_sends_booking_no_show(): void
    {
        $booking = $this->book('2026-11-09T09:00Z');
        $this->travelTo('2026-11-09 09:20:00');

        $this->actingAs($this->host)
            ->post("/dashboard/bookings/{$booking->uid}/outcome", ['outcome' => 'no_show'])
            ->assertSessionHas('status', 'booking-no-show');

        $body = $this->sentBody('booking.no_show');
        $this->assertMatchesContract('booking.no_show', $body);
        $this->assertSame('no_show', $body['booking']['status']);
        $this->assertSame($booking->uid, $body['booking']['id']);
    }

    public function test_automatic_completion_sends_booking_completed(): void
    {
        $booking = $this->book('2026-11-09T09:00Z');
        $this->travelTo('2026-11-09 10:30:00');

        $this->artisan('bookings:complete')->assertSuccessful();

        $body = $this->sentBody('booking.completed');
        $this->assertMatchesContract('booking.completed', $body);
        $this->assertSame('completed', $body['booking']['status']);
        $this->assertSame($booking->uid, $body['booking']['id']);
    }

    public function test_team_bookings_list_every_host_and_the_team(): void
    {
        $team = Team::factory()->create(['slug' => 'sales']);
        $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@host.test', 'timezone' => 'UTC']);
        $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@host.test', 'timezone' => 'UTC']);
        $team->members()->attach([$alice->id => ['role' => 'member'], $bob->id => ['role' => 'member']]);
        $eventType = EventType::factory()->forTeam($team, SchedulingType::Collective)->create(['slug' => 'demo', 'duration_minutes' => 30]);
        $eventType->hosts()->attach([$alice->id, $bob->id], ['is_fixed' => true]);

        $booking = $this->book('2026-11-09T09:00Z', $eventType);

        $body = $this->sentBody('booking.created');
        $this->assertSame('collective', $body['booking']['event_type']['kind']);
        $this->assertSame('sales', $body['booking']['event_type']['team_slug']);
        $this->assertSame($booking->host->email, $body['booking']['organizer_email']);
        $this->assertEqualsCanonicalizing(
            [['alice@host.test', $booking->host_id === $alice->id], ['bob@host.test', $booking->host_id === $bob->id]],
            collect($body['booking']['hosts'])->map(fn ($h) => [$h['email'], $h['is_organizer']])->all(),
        );
    }

    // --- Configuration --------------------------------------------------

    public function test_nothing_is_sent_when_the_integration_is_not_configured(): void
    {
        config(['services.crm.install_id' => null, 'services.crm.webhook_url' => null, 'services.crm.webhook_secret' => null]);
        Queue::fake();

        $this->book('2026-11-09T09:00Z');

        Queue::assertNotPushed(SendCrmWebhook::class);
        Http::assertNothingSent();
    }

    public function test_a_partial_configuration_sends_nothing(): void
    {
        config(['services.crm.webhook_secret' => '']);
        Queue::fake();

        $this->book('2026-11-09T09:00Z');

        Queue::assertNotPushed(SendCrmWebhook::class);
    }

    public function test_webhooks_go_through_the_queue_after_the_booking(): void
    {
        Queue::fake();

        $booking = $this->book('2026-11-09T09:00Z');

        Queue::assertPushed(SendCrmWebhook::class, fn (SendCrmWebhook $job) => $job->payload['type'] === 'booking.created'
            && $job->payload['booking']['id'] === $booking->uid);
        Http::assertNothingSent();
    }

    // --- Delivery -------------------------------------------------------

    public function test_retries_back_off_as_the_contract_suggests(): void
    {
        $job = new SendCrmWebhook($this->payload());

        $this->assertSame(6, $job->tries);
        $this->assertSame([60, 300, 1800, 7200, 21600], $job->backoff());
        $this->assertSame(10, SendCrmWebhook::TIMEOUT_SECONDS);
    }

    public function test_every_attempt_is_signed_with_a_fresh_timestamp_and_the_same_event_id(): void
    {
        $job = new SendCrmWebhook($payload = $this->payload());

        $job->handle();
        $this->travel(2)->hours();
        $job->handle();

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $sent);
        $this->assertNotSame($sent[0]->header('X-Marki-Timestamp'), $sent[1]->header('X-Marki-Timestamp'));
        $this->assertSame([$payload['id']], $sent[0]->header('X-Marki-Delivery'));
        $this->assertSame([$payload['id']], $sent[1]->header('X-Marki-Delivery'));
        $this->assertSame($sent[0]->body(), $sent[1]->body());
    }

    public function test_a_duplicate_response_counts_as_delivered(): void
    {
        $this->respond = fn () => Http::response(['status' => 'duplicate'], 200);
        $job = (new SendCrmWebhook($this->payload()))->withFakeQueueInteractions();

        $job->handle();

        $job->assertNotFailed();
    }

    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public static function retryableResponses(): array
    {
        return [
            'server error' => [500, []],
            'rate limited' => [429, []],
            'stale timestamp' => [400, ['error' => ['code' => 'stale_timestamp']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('retryableResponses')]
    public function test_temporary_failures_are_retried(int $status, array $body): void
    {
        $this->respond = fn () => Http::response($body, $status);
        $job = (new SendCrmWebhook($this->payload()))->withFakeQueueInteractions();

        // Throwing hands the job back to the queue, which retries it after the backoff.
        $thrown = null;
        try {
            $job->handle();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the job to throw.');
        $job->assertNotFailed();
    }

    public function test_connection_errors_are_retried(): void
    {
        $this->respond = fn () => throw new ConnectionException('timed out');
        $job = (new SendCrmWebhook($this->payload()))->withFakeQueueInteractions();

        $this->expectException(RuntimeException::class);
        $job->handle();
    }

    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public static function finalResponses(): array
    {
        return [
            'invalid payload' => [400, ['error' => ['code' => 'invalid_payload']]],
            'invalid signature' => [401, ['error' => ['code' => 'invalid_signature']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('finalResponses')]
    public function test_rejections_that_a_retry_cannot_fix_fail_at_once(int $status, array $body): void
    {
        $this->respond = fn () => Http::response($body, $status);
        $job = (new SendCrmWebhook($this->payload()))->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailed();
    }

    // --- Helpers --------------------------------------------------------

    private function book(string $start, ?EventType $eventType = null): Booking
    {
        $eventType ??= $this->eventType;
        $question = $eventType->questions()->firstOrCreate(['label' => 'Your role'], ['type' => 'text', 'position' => 0]);

        [$booking] = app(BookingService::class)->book(
            $eventType,
            CarbonImmutable::parse($start),
            new GuestDetails('Jane Doe', 'jane.doe@acme.example', 'Africa/Tunis', phone: '+216 71 234 567', answers: [
                ['question_id' => $question->id, 'label' => 'Your role', 'value' => 'Head of Sales', 'position' => 0],
            ]),
        );

        return $booking->fresh(['host', 'hosts']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return BookingPayload::make('booking.created', Booking::factory()->at($this->eventType, '2026-11-09 09:00')->create());
    }

    /**
     * The decoded body of the one webhook of $type that was sent.
     *
     * @return array<string, mixed>
     */
    private function sentBody(string $type): array
    {
        $requests = Http::recorded(fn (Request $request) => $request->header('X-Marki-Event') === [$type]);
        $this->assertCount(1, $requests, "Expected one {$type} webhook.");

        return json_decode($requests->first()[0]->body(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Same keys, nested the same way, as the contract's sample for $type.
     *
     * @param  array<string, mixed>  $body
     */
    private function assertMatchesContract(string $type, array $body): void
    {
        $sample = json_decode(file_get_contents(base_path("tests/Fixtures/crm-webhooks/{$type}.json")), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($this->shape($sample), $this->shape($body), "The {$type} body does not match the contract.");
        $this->assertSame($type, $body['type']);
    }

    private function shape(mixed $value): mixed
    {
        if (! is_array($value)) {
            return 'value';
        }

        if (array_is_list($value)) {
            return $value === [] ? [] : [$this->shape($value[0])];
        }

        ksort($value);

        return array_map(fn ($v) => $this->shape($v), $value);
    }
}
