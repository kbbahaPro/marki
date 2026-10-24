{!! __(':guest rescheduled', ['guest' => $booking->guest_name]) !!}

{!! __('Was') !!}: {!! $previousWhen['date'] !!}, {!! $previousWhen['time'] !!}
{!! __('Now') !!}: {!! $when['date'] !!}, {!! $when['time'] !!} ({!! $when['timezone'] !!})
{!! __('Where') !!}: {!! $where['url'] ?? $where['text'] !!}

{!! config('app.name') !!}
