<?php

namespace App\Services\Crm;

use App\Enums\BookingStatus;
use App\Enums\SchedulingType;
use App\Models\Booking;
use App\Models\BookingAnswer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The body of a booking webhook, as defined by the CRM's contract v1
 * (WEBHOOKS.md). Every event carries the whole booking, so the receiver
 * can apply any event on its own.
 */
class BookingPayload
{
    public const VERSION = 1;

    /**
     * @param  'booking.created'|'booking.rescheduled'|'booking.cancelled'|'booking.no_show'|'booking.completed'  $type
     * @return array<string, mixed>
     */
    public static function make(string $type, Booking $booking, ?CarbonInterface $occurredAt = null): array
    {
        $booking->loadMissing(['eventType.team', 'host', 'hosts', 'answers.question', 'rescheduledFrom']);
        $eventType = $booking->eventType;

        return [
            'id' => (string) Str::ulid(),
            'type' => $type,
            'version' => self::VERSION,
            'occurred_at' => self::time($occurredAt ?? now()),
            'organization_id' => (string) config('services.crm.install_id'),
            'booking' => [
                'id' => $booking->uid,
                'previous_booking_id' => $type === 'booking.rescheduled' ? $booking->rescheduledFrom?->uid : null,
                'status' => $booking->status->value,
                'start_at' => self::time($booking->start_at),
                'end_at' => self::time($booking->end_at),
                'timezone' => $booking->guest_timezone,
                'location' => [
                    'type' => $booking->location_type->value,
                    'value' => $booking->location_value,
                    'url' => $booking->meeting_url,
                ],
                // The guest's manage link is a secret; never sent.
                'manage_url' => null,
                'event_type' => [
                    'id' => $eventType->id,
                    'slug' => $eventType->slug,
                    'name' => $eventType->title,
                    'kind' => $eventType->scheduling_type === SchedulingType::Single ? 'personal' : $eventType->scheduling_type->value,
                    'team_slug' => $eventType->team?->slug,
                ],
                'organizer_email' => $booking->host->email,
                'hosts' => self::hosts($booking),
                'invitee' => [
                    'name' => $booking->guest_name,
                    'email' => $booking->guest_email,
                    'phone' => $booking->guest_phone,
                    'notes' => $booking->guest_notes,
                ],
                'answers' => $booking->answers->map(fn (BookingAnswer $answer) => [
                    'question_id' => $answer->question_id,
                    'label' => $answer->label,
                    'type' => $answer->question?->type->value,
                    'value' => $answer->value,
                ])->values()->all(),
                'cancellation' => $booking->status === BookingStatus::Cancelled ? [
                    'reason' => $booking->cancel_reason,
                    'cancelled_by' => $booking->cancelled_by,
                    'cancelled_at' => $booking->cancelled_at ? self::time($booking->cancelled_at) : null,
                ] : null,
            ],
        ];
    }

    /**
     * @return list<array{email: string, name: string, is_organizer: bool}>
     */
    private static function hosts(Booking $booking): array
    {
        $hosts = $booking->hosts->isEmpty() ? collect([$booking->host]) : $booking->hosts;

        return $hosts->map(fn (User $host) => [
            'email' => $host->email,
            'name' => $host->name,
            'is_organizer' => $host->id === $booking->host_id,
        ])->values()->all();
    }

    private static function time(CarbonInterface $time): string
    {
        return $time->toImmutable()->utc()->toIso8601String();
    }
}
