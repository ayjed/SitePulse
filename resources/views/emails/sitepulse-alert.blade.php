<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SitePulse Alert</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #0f172a; line-height: 1.5; padding: 24px;">
    <h1 style="font-size: 18px; margin: 0 0 12px;">SitePulse Monitor Alert: {{ $alertType->label() }}</h1>

    <p style="margin: 0 0 8px;"><strong>Monitor:</strong> {{ $sanitizedMonitorName }}</p>
    <p style="margin: 0 0 8px;"><strong>URL:</strong> {{ $displayUrl }}</p>
    <p style="margin: 0 0 8px;"><strong>Recorded At:</strong> {{ $occurredAtUtc }}</p>

    @if ($formattedDuration !== null)
        <p style="margin: 0 0 8px;"><strong>Downtime Duration:</strong> {{ $formattedDuration }}</p>
    @endif

    @if ($tlsExpiresAtUtc !== null)
        <p style="margin: 0 0 8px;"><strong>Certificate Expires At:</strong> {{ $tlsExpiresAtUtc }}</p>
    @endif

    @if ($safeSummary !== null)
        <p style="margin: 0 0 8px;"><strong>Reason:</strong> {{ $safeSummary }}</p>
    @endif
</body>
</html>
