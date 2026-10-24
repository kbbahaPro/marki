{!! __('New booking with :guest', ['guest' => $booking->guest_name]) !!}

{!! __('What') !!}: {!! $booking->eventType->title !!}
{!! __('When') !!}: {!! $when['date'] !!}, {!! $when['time'] !!} ({!! $when['timezone'] !!})
{!! __('Where') !!}: {!! $where['url'] ?? $where['text'] !!}

{!! __('Name') !!}: {!! $booking->guest_name !!}
{!! __('Email') !!}: {!! $booking->guest_email !!}
@if ($booking->guest_phone)
{!! __('Phone') !!}: {!! $booking->guest_phone !!}
@endif
{!! __('Their time zone') !!}: {!! $booking->guest_timezone !!}
@foreach ($booking->answers as $answer)
{!! $answer->label !!}: {!! $answer->value ?? '—' !!}
@endforeach
@if ($booking->guest_notes)

{!! __('Notes') !!}:
{!! $booking->guest_notes !!}
@endif

{!! config('app.name') !!}
