<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;color:#182230;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader ?? $headline }}</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#f4f7fb;padding:28px 12px;">
        <tr><td align="center">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dfe7f2;border-radius:18px;overflow:hidden;">
                <tr><td style="padding:26px 34px;background:linear-gradient(135deg,#073b77,#0a5eb8);color:#ffffff;">
                    <div style="font-size:13px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;opacity:.88;">{{ $brand }}</div>
                    <div style="font-size:25px;font-weight:700;line-height:1.25;margin-top:7px;">{{ $headline }}</div>
                </td></tr>
                <tr><td style="padding:34px;">
                    @if(!empty($greeting))
                        <p style="margin:0 0 17px;font-size:16px;line-height:1.55;">{{ $greeting }}</p>
                    @endif
                    @if(!empty($body))
                        <div style="font-size:16px;line-height:1.65;color:#344054;">{!! nl2br(e($body)) !!}</div>
                    @endif
                    @if(!empty($code))
                        <div style="margin:25px 0;padding:18px;border:1px solid #b9d4f6;border-radius:12px;background:#eff7ff;text-align:center;letter-spacing:7px;font-size:28px;font-weight:700;color:#073b77;">{{ $code }}</div>
                    @endif
                    @if(!empty($action_url) && !empty($action_label))
                        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:27px 0 18px;"><tr><td style="border-radius:9px;background:#075eb8;">
                            <a href="{{ $action_url }}" style="display:inline-block;padding:13px 20px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;">{{ $action_label }}</a>
                        </td></tr></table>
                    @endif
                    @if(!empty($note))
                        <p style="margin:20px 0 0;font-size:13px;line-height:1.55;color:#667085;">{{ $note }}</p>
                    @endif
                </td></tr>
                <tr><td style="padding:20px 34px;border-top:1px solid #e7edf5;background:#fafcff;color:#667085;font-size:12px;line-height:1.55;">
                    {{ $brand }} · Comunicazione di servizio
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
