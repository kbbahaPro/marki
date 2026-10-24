<x-mail::message>
# {{ __('Your Google Calendar is disconnected') }}

{{ __(':app can no longer read the Google Calendar for :email. Access may have been revoked or expired.', ['app' => config('app.name'), 'email' => $accountEmail]) }}

{{ __('To avoid double-booking you, your booking pages show no available times until you reconnect.') }}

<x-mail::button :url="$url">
{{ __('Reconnect Google Calendar') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
