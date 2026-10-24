{!! __('Your booking was cancelled') !!}

{!! $booking->eventType->title !!} – {!! $booking->hostNames() !!}
{!! $when['date'] !!}, {!! $when['time'] !!} ({!! $when['timezone'] !!})
@if ($booking->cancel_reason)

{!! __('Reason') !!}: {!! $booking->cancel_reason !!}
@endif
@if ($bookAgainUrl)

{!! __('Book a new time') !!}: {!! $bookAgainUrl !!}
@endif

{!! config('app.name') !!}
