<x-mail::message>
# {{ __('You have been invited') }}

@if ($inviterName)
{{ __(':name has invited you to join :app to share your booking pages.', ['name' => $inviterName, 'app' => config('app.name')]) }}
@else
{{ __('You have been invited to join :app to share your booking pages.', ['app' => config('app.name')]) }}
@endif

<x-mail::button :url="$url">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This link expires on :date (UTC). If you were not expecting this invitation, you can ignore this email.', ['date' => $expiresAt->format('F j, Y H:i')]) }}

{{ config('app.name') }}
</x-mail::message>
