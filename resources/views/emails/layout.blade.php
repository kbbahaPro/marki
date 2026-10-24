<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                    <tr>
                        <td style="padding:0 4px 16px;">
                            {{-- Email clients block SVG, so the mark is a PNG. --}}
                            <img src="{{ config('booking.brand.logo_url') ?: asset('brand/mark-128.png') }}" alt="" height="28" style="vertical-align:middle;border:0;height:28px;width:auto;">
                            @unless (config('booking.brand.logo_url'))
                                <span style="vertical-align:middle;margin-left:6px;font-size:20px;font-weight:700;letter-spacing:-0.5px;color:#12233d;">{{ config('booking.brand.wordmark') ?: config('app.name') }}</span>
                            @endunless
                        </td>
                    </tr>
                </table>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;">
                    <tr>
                        <td style="padding:32px 28px;font-size:15px;line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;font-size:12px;color:#9ca3af;">
                    {{ config('app.name') }}@if (config('booking.brand.tagline')) · {{ config('booking.brand.tagline') }}@endif
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
