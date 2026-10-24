{{-- Expects $manageUrl and $rescheduleUrl: the guest's private links. --}}
<p style="margin:0;color:#6b7280;font-size:13px;">
    {{ __('Need to make a change?') }}
    <a href="{{ $rescheduleUrl }}" style="color:#2563eb;">{{ __('Reschedule') }}</a>
    ·
    <a href="{{ $manageUrl }}" style="color:#2563eb;">{{ __('Cancel') }}</a>
</p>
<p style="margin:8px 0 0;color:#9ca3af;font-size:12px;">{{ __('These links are private to you; please do not forward this email.') }}</p>
