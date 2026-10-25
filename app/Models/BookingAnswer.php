<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['question_id', 'label', 'value', 'position'])]
class BookingAnswer extends Model
{
    public $timestamps = false;

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The question as it is now (null once it was removed from the event type).
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(EventTypeQuestion::class, 'question_id');
    }
}
