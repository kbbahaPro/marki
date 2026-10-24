{!! __('Your meeting link is ready') !!}

{!! __(':title with :host on :date at :time (:timezone).', ['title' => $booking->eventType->title, 'host' => $booking->hostNames(), 'date' => $when['date'], 'time' => $when['time'], 'timezone' => $when['timezone']]) !!}

{!! $booking->meeting_url !!}

{!! config('app.name') !!}
