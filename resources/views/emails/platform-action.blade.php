<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:20px 32px;border-bottom:1px solid #e5e7eb;font-size:18px;font-weight:bold;color:#b91c1c;text-align:{{ $rtl ? 'right' : 'left' }};">
                {{ $platformName }}
            </td>
        </tr>
        <tr>
            <td style="padding:28px 32px 8px;font-size:15px;line-height:1.6;text-align:{{ $rtl ? 'right' : 'left' }};">
                {!! nl2br(e($bodyText)) !!}
            </td>
        </tr>
        <tr>
            <td style="padding:16px 32px 8px;text-align:center;">
                <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 24px;background:#b91c1c;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:15px;">{{ $actionText }}</a>
            </td>
        </tr>
        <tr>
            <td style="padding:8px 32px 24px;font-size:12px;line-height:1.5;color:#6b7280;word-break:break-all;text-align:{{ $rtl ? 'right' : 'left' }};">
                {{ __('platform_emails.link_fallback') }}<br>
                <a href="{{ $actionUrl }}" style="color:#6b7280;">{{ $actionUrl }}</a>
            </td>
        </tr>
        <tr>
            <td style="padding:14px 32px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;text-align:{{ $rtl ? 'right' : 'left' }};">
                {{ __('platform_emails.sent_by', ['platform' => $platformName]) }}
            </td>
        </tr>
    </table>
</body>
</html>
