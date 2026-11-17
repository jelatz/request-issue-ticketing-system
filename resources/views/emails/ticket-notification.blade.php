<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $notification->title }}</title>
</head>
<body style="margin: 0; padding: 24px; background-color: #f8fafc; font-family: Arial, Helvetica, sans-serif; color: #0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px;">
        <tr>
            <td style="padding: 20px 24px; background-color: #0f172a; color: #ffffff; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                <div style="font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #94a3b8;">
                    {{ config('app.name', 'CallTek Ticketing') }}
                </div>
                <div style="margin-top: 6px; font-size: 18px; font-weight: 700; color: #ffffff;">
                    {{ $notification->title }}
                </div>
            </td>
        </tr>
        <tr>
            <td style="padding: 24px;">
                @if ($ticket)
                    <p style="margin: 0 0 12px 0; font-size: 13px; color: #475569;">
                        <strong>Ticket Reference:</strong> {{ $ticket->ticket_number }} &mdash; {{ $ticket->title }}
                    </p>
                @endif

                <p style="margin: 0 0 20px 0; font-size: 15px; line-height: 1.6; color: #1e293b;">
                    {{ $notification->message }}
                </p>

                @if ($actionUrl)
                    <p style="margin: 24px 0 8px 0;">
                        <a href="{{ $actionUrl }}" style="display: inline-block; padding: 10px 18px; background-color: #2563eb; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600; border-radius: 6px;">
                            View Ticket Details
                        </a>
                    </p>
                    <p style="margin: 12px 0 0 0; font-size: 12px; color: #64748b; word-break: break-all;">
                        Direct link: {{ $actionUrl }}
                    </p>
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding: 16px 24px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b;">
                This is an automated notification from the CallTek Request &amp; Issue Ticketing System.
            </td>
        </tr>
    </table>
</body>
</html>
