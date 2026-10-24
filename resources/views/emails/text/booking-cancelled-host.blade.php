{!! __(':guest cancelled', ['guest' => $booking->guest_name]) !!}

{!! $booking->eventType->title !!}
{!! $when['date'] !!}, {!! $when['time'] !!} ({!! $when['timezone'] !!})
@if ($booking->cancel_reason)

{!! __('Reason') !!}: {!! $booking->cancel_reason !!}
@endif

{!! config('app.name') !!}
