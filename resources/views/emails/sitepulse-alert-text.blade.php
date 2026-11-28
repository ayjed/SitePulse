SitePulse Monitor Alert: {{ $alertType->label() }}

Monitor: {{ $sanitizedMonitorName }}
URL: {{ $displayUrl }}
Recorded At: {{ $occurredAtUtc }}
@if ($formattedDuration !== null)
Downtime Duration: {{ $formattedDuration }}
@endif
@if ($tlsExpiresAtUtc !== null)
Certificate Expires At: {{ $tlsExpiresAtUtc }}
@endif
@if ($safeSummary !== null)
Reason: {{ $safeSummary }}
@endif
