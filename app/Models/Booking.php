<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\LocationType;
use App\Enums\SyncStatus;
use App\Services\Scheduling\Period;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'event_type_id', 'host_id', 'start_at', 'end_at', 'blocked_start_at', 'blocked_end_at',
    'guest_name', 'guest_email', 'guest_phone', 'guest_timezone', 'guest_notes',
    'status', 'manage_token_hash', 'location_type', 'location_value', 'rescheduled_from_id',
    'ical_uid', 'ical_sequence', 'manage_token',
])]
#[Hidden(['manage_token_hash', 'manage_token', 'active_start_at'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'blocked_start_at' => 'immutable_datetime',
            'blocked_end_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'status' => BookingStatus::class,
            'location_type' => LocationType::class,
            'sync_status' => SyncStatus::class,
            'ical_sequence' => 'integer',
            'manage_token' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // The organizer is always one of the booking's hosts.
        static::created(fn (Booking $booking) => $booking->hosts()->attach($booking->host_id, ['is_organizer' => true]));
    }

    /**
     * Pending reminders for this booking, scheduled from the event type's offsets.
     */
    public function scheduleReminders(): void
    {
        $offsets = $this->eventType->reminder_offsets ?? [];

        foreach ($offsets as $offset) {
            $sendAt = $this->start_at->subMinutes($offset);

            // A reminder whose moment has already passed would arrive late and confusing.
            if ($sendAt->isFuture()) {
                $this->reminders()->firstOrCreate(['offset_minutes' => $offset], ['send_at' => $sendAt]);
            }
        }
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class);
    }

    /**
     * Find a booking from the raw token in a guest's manage link.
     */
    public static function findByManageToken(string $token): ?self
    {
        return static::where('manage_token_hash', hash('sha256', $token))->first();
    }

    /**
     * Whether the guest can still cancel or move it.
     */
    public function isChangeable(): bool
    {
        return $this->status === BookingStatus::Confirmed && $this->start_at->isFuture();
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function rescheduledTo(): HasOne
    {
        return $this->hasOne(self::class, 'rescheduled_from_id');
    }

    /**
     * The ULID goes in the public "uid" column; "id" stays an integer.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', BookingStatus::Confirmed);
    }

    /**
     * Bookings that are on or were held: not cancelled or moved.
     */
    public function scopeKept(Builder $query): void
    {
        $query->whereIn('status', BookingStatus::kept());
    }

    /**
     * Whether a host can mark it completed or no-show: it has started and was not cancelled or moved.
     */
    public function canRecordOutcome(): bool
    {
        return $this->status->isKept() && ! $this->start_at->isFuture();
    }

    /**
     * Bookings a user attends, as organizer or co-host.
     */
    public function scopeAttendedBy(Builder $query, int $userId): void
    {
        // host_id is checked directly too, so the organizer never depends on the booking_hosts row.
        $query->where(fn (Builder $q) => $q
            ->where('host_id', $userId)
            ->orWhereIn('id', fn ($sub) => $sub->select('booking_id')->from('booking_hosts')->where('user_id', $userId)));
    }

    /**
     * Confirmed bookings a host attends whose buffered time touches $period.
     */
    public function scopeBlockingHost(Builder $query, int $hostId, Period $period): void
    {
        $query->confirmed()
            ->attendedBy($hostId)
            ->where('blocked_start_at', '<', $period->end)
            ->where('blocked_end_at', '>', $period->start);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class)->withTrashed();
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /**
     * The person who cancelled, when a host did (null for guests and older bookings).
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * Everyone hosting this booking, organizer first.
     */
    public function hosts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'booking_hosts')
            ->withPivot('is_organizer')
            ->orderByDesc('booking_hosts.is_organizer')
            ->orderBy('users.name');
    }

    /**
     * Who can see and change this booking in the app: its hosts, and for team
     * bookings the team's admins and global admins. Personal bookings stay
     * private to their host, even from admins.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->host_id === $user->id || $this->hosts->contains($user)) {
            return true;
        }

        $team = $this->eventType->team;

        return $team !== null && $team->isManagedBy($user);
    }

    /**
     * How a host-side cancellation reads to $viewer: "you", a name, or just "host".
     */
    public function cancelledByLabel(User $viewer): string
    {
        return match (true) {
            $this->cancelled_by !== 'host' => __('Cancelled by guest'),
            ($this->cancelled_by_user_id ?? $this->host_id) === $viewer->id => __('Cancelled by you'),
            default => __('Cancelled by :name', ['name' => $this->cancelledBy?->name ?? $this->host->name]),
        };
    }

    /**
     * Hosts other than the organizer (team bookings only).
     *
     * @return Collection<int, User>
     */
    public function coHosts(): Collection
    {
        return $this->hosts->reject(fn (User $host) => $host->id === $this->host_id)->values();
    }

    /**
     * "Alice", "Alice and Bob" or "Alice, Bob and Carol", for guest-facing text.
     */
    public function hostNames(): string
    {
        $names = $this->hosts->isEmpty() ? collect([$this->host->name]) : $this->hosts->pluck('name');

        return $names->join(', ', ' '.__('and').' ');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(BookingAnswer::class)->orderBy('position');
    }
}
