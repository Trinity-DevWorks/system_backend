<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:28px 32px;font-size:15px;line-height:1.6;text-align:{{ $rtl ? 'right' : 'left' }};">
                {!! nl2br(e($bodyText)) !!}
            </td>
        </tr>
        <tr>
            <td style="padding:14px 32px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;text-align:{{ $rtl ? 'right' : 'left' }};">
                {{ __('document_emails.sent_from', ['company' => $companyName]) }}
            </td>
        </tr>
    </table>
</body>
</html>
