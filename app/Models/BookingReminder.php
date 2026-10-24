<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offset_minutes', 'send_at'])]
class BookingReminder extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offset_minutes' => 'integer',
            'send_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function scopeDue(Builder $query): void
    {
        $query->whereNull('sent_at')->where('send_at', '<=', now());
    }

    /**
     * Mark as sent unless another scheduler run already did; true if this call won.
     */
    public function claim(): bool
    {
        return static::query()->whereKey($this->id)->whereNull('sent_at')->update(['sent_at' => now()]) === 1;
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
