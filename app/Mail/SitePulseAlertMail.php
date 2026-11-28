<?php

namespace App\Mail;

use App\Enums\IncidentNotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Escaped, credential-free SitePulse operational alert email for outages, recoveries, and TLS warnings.
 */
class SitePulseAlertMail extends Mailable
{
    public string $sanitizedMonitorName;

    public string $displayUrl;

    public ?string $safeSummary;

    public string $occurredAtUtc;

    public ?string $tlsExpiresAtUtc;

    public ?string $formattedDuration;

    public function __construct(
        public readonly IncidentNotificationType $alertType,
        string $monitorName,
        string $monitorUrl,
        CarbonImmutable $occurredAt,
        ?string $summary = null,
        ?int $durationSeconds = null,
        ?CarbonImmutable $tlsExpiresAt = null,
    ) {
        $this->sanitizedMonitorName = self::sanitizeSingleLine($monitorName, 80, 'Monitor');
        $this->displayUrl = self::sanitizeDisplayUrl($monitorUrl);
        $this->safeSummary = $summary !== null ? self::sanitizeSingleLine($summary, 255, '') : null;
        if ($this->safeSummary === '') {
            $this->safeSummary = null;
        }
        $this->occurredAtUtc = $occurredAt->utc()->format('Y-m-d H:i:s \U\T\C');
        $this->tlsExpiresAtUtc = $tlsExpiresAt?->utc()->format('Y-m-d H:i:s \U\T\C');
        $this->formattedDuration = $durationSeconds !== null ? self::formatDuration(max(0, $durationSeconds)) : null;
    }

    /**
     * Build a safe, header-injection-free, credential-free email subject.
     */
    public static function formatSubject(IncidentNotificationType $type, string $monitorName): string
    {
        $cleanName = self::sanitizeSingleLine($monitorName, 80, 'Monitor');

        return match ($type) {
            IncidentNotificationType::DOWN => "[SitePulse] DOWN: {$cleanName}",
            IncidentNotificationType::RECOVERY => "[SitePulse] RECOVERED: {$cleanName}",
            IncidentNotificationType::TLS_WARNING => "[SitePulse] TLS Expiry Warning: {$cleanName}",
        };
    }

    /**
     * Strip control characters and bound single-line alert metadata cleanly.
     */
    public static function sanitizeSingleLine(string $value, int $maxLength, string $fallback): string
    {
        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned)) ?? '';

        if ($cleaned === '') {
            return $fallback;
        }

        if (mb_strlen($cleaned, 'UTF-8') > $maxLength) {
            return mb_substr($cleaned, 0, $maxLength - 3, 'UTF-8').'...';
        }

        return $cleaned;
    }

    /**
     * Strip credentials and unsafe schemes before displaying a monitor URL in alert text or HTML.
     */
    public static function sanitizeDisplayUrl(string $url): string
    {
        $cleaned = self::sanitizeSingleLine($url, 255, '');
        $cleaned = preg_replace('#(https?://)[^/@\s]+@#i', '$1[REDACTED]@', $cleaned) ?? $cleaned;
        $parts = parse_url($cleaned);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '[restricted-scheme]';
        }

        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';

        return "{$scheme}://{$host}{$port}{$path}";
    }

    /**
     * Format downtime duration seconds in concise human-readable text.
     */
    public static function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        }

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        }

        return sprintf('%ds', $secs);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::formatSubject($this->alertType, $this->sanitizedMonitorName),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sitepulse-alert',
            text: 'emails.sitepulse-alert-text',
        );
    }
}
