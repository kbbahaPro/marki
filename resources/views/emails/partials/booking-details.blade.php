{{-- Expects $booking, $when, $where. All values are escaped by Blade. --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:20px 0;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;">
    <tr>
        <td style="padding:12px 12px 4px 0;width:72px;color:#6b7280;vertical-align:top;">{{ __('What') }}</td>
        <td style="padding:12px 0 4px;font-weight:600;">{{ $booking->eventType->title }}</td>
    </tr>
    <tr>
        <td style="padding:4px 12px 4px 0;color:#6b7280;vertical-align:top;">{{ __('When') }}</td>
        <td style="padding:4px 0;">{{ $when['date'] }}<br>{{ $when['time'] }} <span style="color:#6b7280;">({{ $when['timezone'] }})</span></td>
    </tr>
    <tr>
        <td style="padding:4px 12px 12px 0;color:#6b7280;vertical-align:top;">{{ __('Where') }}</td>
        <td style="padding:4px 0 12px;">
            @if ($where['url'])
                <a href="{{ $where['url'] }}" style="color:#2563eb;">{{ $where['url'] }}</a>
            @else
                {{ $where['text'] }}
            @endif
        </td>
    </tr>
</table>
