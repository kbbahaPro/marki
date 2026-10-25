<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingOutcomeRecorded;
use App\Events\BookingRescheduled;
use App\Jobs\SendCrmWebhook;
use App\Services\Crm\BookingPayload;
use App\Services\Crm\CrmConfig;

/**
 * Queues a webhook to the CRM for every booking change, when the
 * integration is configured. The events fire after the booking committed.
 */
class QueueCrmWebhook
{
    public function handle(BookingCreated|BookingRescheduled|BookingCancelled|BookingOutcomeRecorded $event): void
    {
        if (! CrmConfig::webhooksEnabled()) {
            return;
        }

        $booking = $event->booking->fresh();

        $type = match (true) {
            $event instanceof BookingCreated => 'booking.created',
            $event instanceof BookingRescheduled => 'booking.rescheduled',
            $event instanceof BookingCancelled => 'booking.cancelled',
            $booking->status === BookingStatus::NoShow => 'booking.no_show',
            default => 'booking.completed',
        };

        $occurredAt = $event instanceof BookingCancelled ? $booking->cancelled_at : now();

        SendCrmWebhook::dispatch(BookingPayload::make($type, $booking, $occurredAt));
    }
}
