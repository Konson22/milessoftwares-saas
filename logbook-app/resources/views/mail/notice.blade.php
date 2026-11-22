<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1b2433">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dde3ec;border-radius:8px">
        <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #dde3ec;font-size:14px;color:#4a5568">
                Miles Logbook · {{ $workspace }}
            </td>
        </tr>
        <tr>
            <td style="padding:24px">
                <h1 style="margin:0 0 12px;font-size:18px;line-height:1.4">{{ $heading }}</h1>
                @if ($text)
                    <p style="margin:0 0 20px;font-size:15px;line-height:1.5">{{ $text }}</p>
                @endif
                <a href="{{ $link }}" style="display:inline-block;padding:10px 18px;background:#0b4fae;color:#ffffff;border-radius:6px;text-decoration:none;font-size:15px">Open in Logbook</a>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px;border-top:1px solid #dde3ec;font-size:12px;color:#6b7280">
                You get this because of your work in Miles Logbook. Turn emails off by category under Notifications in Logbook.
            </td>
        </tr>
    </table>
</body>
</html>
