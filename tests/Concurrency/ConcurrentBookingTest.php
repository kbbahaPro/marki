<?php

namespace Tests\Concurrency;

use App\Enums\SchedulingType;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves that simultaneous booking attempts cannot double-book a host.
 *
 * Two separate PHP processes (like two web requests) book at the same instant
 * against a real MySQL/MariaDB database; SQLite cannot be used because it has
 * no row locks. Inside the booking transaction each process pauses between
 * the availability check and the insert (BOOKING_TEST_LOCK_PAUSE_MS), which
 * is exactly where a race would double-book without the host lock.
 *
 * Configure with TEST_MYSQL_HOST, TEST_MYSQL_PORT, TEST_MYSQL_DATABASE,
 * TEST_MYSQL_USERNAME and TEST_MYSQL_PASSWORD (defaults: 127.0.0.1, 3306,
 * calendar_testing, root, empty). The database is wiped on every run, so its
 * name must contain "test". The test is skipped when the server is unreachable.
 */
class ConcurrentBookingTest extends TestCase
{
    private const PAUSE_MS = 400;

    /** @var array<string, string> */
    private array $db;

    /** @var list<int> Host (organizer) ids of the bookings made by the last race. */
    private array $bookedHosts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = [
            'host' => getenv('TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_MYSQL_PORT') ?: '3306',
            'database' => getenv('TEST_MYSQL_DATABASE') ?: 'calendar_testing',
            'username' => getenv('TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('TEST_MYSQL_PASSWORD') ?: '',
        ];

        if (! str_contains($this->db['database'], 'test')) {
            $this->markTestSkipped("Refusing to wipe database \"{$this->db['database']}\": its name must contain \"test\".");
        }

        try {
            new PDO("mysql:host={$this->db['host']};port={$this->db['port']};dbname={$this->db['database']}", $this->db['username'], $this->db['password']);
        } catch (PDOException $e) {
            $this->markTestSkipped('MySQL test database unavailable: '.$e->getMessage());
        }

        config([
            'database.connections.concurrency' => array_merge(config('database.connections.mysql'), $this->db),
            'database.default' => 'concurrency',
        ]);
        DB::purge('concurrency');

        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
    }

    public function test_only_one_of_two_simultaneous_bookings_for_the_same_slot_succeeds(): void
    {
        $eventType = $this->eventType(User::factory()->create(), 30);

        $results = $this->race([
            [$eventType->id, '2026-11-09T10:00:00Z'],
            [$eventType->id, '2026-11-09T10:00:00Z'],
        ]);

        $this->assertEqualsCanonicalizing(['BOOKED', 'UNAVAILABLE'], $results);
        $this->assertSame(1, Booking::count());
    }

    public function test_overlapping_bookings_with_different_start_times_are_serialised(): void
    {
        // 10:00–11:00 and 10:30–11:00 overlap but start at different times, so the
        // unique index cannot catch them: only the host lock prevents this.
        $host = User::factory()->create();
        $long = $this->eventType($host, 60);
        $short = $this->eventType($host, 30);

        $results = $this->race([
            [$long->id, '2026-11-09T10:00:00Z'],
            [$short->id, '2026-11-09T10:30:00Z'],
        ]);

        $this->assertEqualsCanonicalizing(['BOOKED', 'UNAVAILABLE'], $results);
        $this->assertSame(1, Booking::count());
    }

    public function test_different_hosts_do_not_block_each_other(): void
    {
        $results = $this->race([
            [$this->eventType(User::factory()->create(), 30)->id, '2026-11-09T10:00:00Z'],
            [$this->eventType(User::factory()->create(), 30)->id, '2026-11-09T10:00:00Z'],
        ]);

        $this->assertSame(['BOOKED', 'BOOKED'], $results);
        $this->assertSame(2, Booking::count());
    }

    public function test_two_round_robin_bookings_for_one_slot_go_to_different_hosts(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $roundRobin = $this->teamEventType(SchedulingType::RoundRobin, [$alice, $bob]);

        $results = $this->race([
            [$roundRobin->id, '2026-11-09T10:00:00Z'],
            [$roundRobin->id, '2026-11-09T10:00:00Z'],
        ]);

        $this->assertSame(['BOOKED', 'BOOKED'], $results);
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id], $this->bookedHosts);
    }

    public function test_round_robin_with_one_free_host_books_once(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $roundRobin = $this->teamEventType(SchedulingType::RoundRobin, [$alice, $bob]);
        Booking::factory()->at($this->eventType($bob, 30), '2026-11-09T10:00:00Z')->create();

        $results = $this->race([
            [$roundRobin->id, '2026-11-09T10:00:00Z'],
            [$roundRobin->id, '2026-11-09T10:00:00Z'],
        ]);

        $this->assertEqualsCanonicalizing(['BOOKED', 'UNAVAILABLE'], $results);
        $this->assertSame([$alice->id], $this->bookedHosts);
    }

    public function test_a_co_host_cannot_be_double_booked_by_a_personal_booking(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();

        $results = $this->race([
            [$this->teamEventType(SchedulingType::Collective, [$alice, $bob])->id, '2026-11-09T10:00:00Z'],
            [$this->eventType($bob, 30)->id, '2026-11-09T10:00:00Z'],
        ]);

        $this->assertEqualsCanonicalizing(['BOOKED', 'UNAVAILABLE'], $results);
        $this->assertSame(1, Booking::count());
    }

    public function test_overlapping_host_sets_listed_in_opposite_orders_do_not_deadlock(): void
    {
        [$alice, $bob, $carol] = User::factory()->count(3)->create();

        // Each pair of bookings shares a host; without one lock order they could wait on each other.
        $results = $this->race([
            [$this->teamEventType(SchedulingType::Collective, [$alice, $bob])->id, '2026-11-09T10:00:00Z'],
            [$this->teamEventType(SchedulingType::Collective, [$bob, $alice])->id, '2026-11-09T11:00:00Z'],
            [$this->teamEventType(SchedulingType::Collective, [$carol, $bob])->id, '2026-11-09T12:00:00Z'],
            [$this->teamEventType(SchedulingType::Collective, [$alice, $carol])->id, '2026-11-09T13:00:00Z'],
        ]);

        $this->assertSame(['BOOKED', 'BOOKED', 'BOOKED', 'BOOKED'], $results);
        $this->assertSame(8, DB::table('booking_hosts')->count());
    }

    private function eventType(User $host, int $minutes): EventType
    {
        return EventType::factory()->for($host)->create(['duration_minutes' => $minutes]);
    }

    /**
     * @param  list<User>  $hosts  Attached in this order.
     */
    private function teamEventType(SchedulingType $type, array $hosts): EventType
    {
        $eventType = EventType::factory()->forTeam(Team::factory()->create(), $type)->create(['duration_minutes' => 30]);

        foreach ($hosts as $host) {
            $eventType->hosts()->attach($host, ['is_fixed' => $type === SchedulingType::Collective]);
        }

        return $eventType;
    }

    /**
     * Start one process per attempt, release them all at the same instant and
     * return each one's outcome ("BOOKED" or "UNAVAILABLE").
     *
     * @param  list<array{0: int, 1: string}>  $attempts  [eventTypeId, startIso]
     * @return list<string>
     */
    private function race(array $attempts): array
    {
        $goAt = sprintf('%.6F', microtime(true) + 4);

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => $this->db['host'],
            'DB_PORT' => $this->db['port'],
            'DB_DATABASE' => $this->db['database'],
            'DB_USERNAME' => $this->db['username'],
            'DB_PASSWORD' => $this->db['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'SESSION_DRIVER' => 'array',
            'BOOKING_TEST_LOCK_PAUSE_MS' => (string) self::PAUSE_MS,
        ];

        $processes = array_map(function (array $attempt) use ($goAt, $env) {
            $process = new Process([PHP_BINARY, __DIR__.'/attempt-booking.php', (string) $attempt[0], $attempt[1], $goAt], base_path(), $env, null, 60);
            $process->start();

            return $process;
        }, $attempts);

        $startedAt = microtime(true);

        $outputs = array_map(function (Process $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), "Booking process failed:\n".$process->getErrorOutput().$process->getOutput());

            return explode(' ', trim($process->getOutput()));
        }, $processes);

        $results = array_map(fn (array $output) => $output[0], $outputs);
        $this->bookedHosts = array_values(array_map(fn (array $output) => (int) $output[2], array_filter($outputs, fn ($output) => $output[0] === 'BOOKED')));

        // If a process started after the release time the race did not really happen.
        $this->assertLessThan((float) $goAt, $startedAt + 0.0, 'Processes were not started before the release time.');

        return $results;
    }
}
