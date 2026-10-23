<?php

namespace Tests\Concurrency;

use App\Models\Booking;
use App\Models\EventType;
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

    private function eventType(User $host, int $minutes): EventType
    {
        return EventType::factory()->for($host)->create(['duration_minutes' => $minutes]);
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

        $results = array_map(function (Process $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), "Booking process failed:\n".$process->getErrorOutput().$process->getOutput());

            return explode(' ', trim($process->getOutput()))[0];
        }, $processes);

        // If a process started after the release time the race did not really happen.
        $this->assertLessThan((float) $goAt, $startedAt + 0.0, 'Processes were not started before the release time.');

        return $results;
    }
}
