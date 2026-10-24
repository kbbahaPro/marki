{!! __('You are scheduled with :host', ['host' => $booking->host->name]) !!}

{!! __('What') !!}: {!! $booking->eventType->title !!}
{!! __('When') !!}: {!! $when['date'] !!}, {!! $when['time'] !!} ({!! $when['timezone'] !!})
{!! __('Where') !!}: {!! $where['url'] ?? $where['text'] !!}

{!! __('The attached invitation adds it to your calendar.') !!}
{!! __('Need to change something? Reply to this email to reach :host.', ['host' => $booking->host->name]) !!}

{!! config('app.name') !!}
