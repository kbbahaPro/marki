{!! __('Your meeting with :host starts :when', ['host' => $booking->hostNames(), 'when' => $startsIn]) !!}

{!! __('What') !!}: {!! $booking->eventType->title !!}
{!! __('When') !!}: {!! $when['date'] !!}, {!! $when['time'] !!} ({!! $when['timezone'] !!})
{!! __('Where') !!}: {!! $where['url'] ?? $where['text'] !!}
@if ($manageUrl)

{!! __('Reschedule') !!}: {!! $rescheduleUrl !!}
{!! __('Cancel') !!}: {!! $manageUrl !!}
@endif

{!! config('app.name') !!}
