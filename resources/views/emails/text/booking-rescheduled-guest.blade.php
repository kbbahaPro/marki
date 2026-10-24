{!! __('Your booking has a new time') !!}

{!! __('Was') !!}: {!! $previousWhen['date'] !!}, {!! $previousWhen['time'] !!}
{!! __('Now') !!}: {!! $when['date'] !!}, {!! $when['time'] !!} ({!! $when['timezone'] !!})
{!! __('Where') !!}: {!! $where['url'] ?? $where['text'] !!}

{!! __('Reschedule') !!}: {!! $rescheduleUrl !!}
{!! __('Cancel') !!}: {!! $manageUrl !!}

{!! config('app.name') !!}
