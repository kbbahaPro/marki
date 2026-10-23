<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\LocationType;
use App\Services\Scheduling\Period;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'event_type_id', 'host_id', 'start_at', 'end_at', 'blocked_start_at', 'blocked_end_at',
    'guest_name', 'guest_email', 'guest_phone', 'guest_timezone', 'guest_notes',
    'status', 'manage_token_hash', 'location_type', 'location_value', 'rescheduled_from_id',
])]
#[Hidden(['manage_token_hash', 'active_start_at'])]
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
        ];
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
     * Confirmed bookings of a host whose buffered time touches $period.
     */
    public function scopeBlockingHost(Builder $query, int $hostId, Period $period): void
    {
        $query->confirmed()
            ->where('host_id', $hostId)
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

    public function answers(): HasMany
    {
        return $this->hasMany(BookingAnswer::class)->orderBy('position');
    }
}
