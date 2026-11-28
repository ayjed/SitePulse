<?php

namespace App\Support\Monitoring;

use App\Enums\CheckResultStatus;
use App\Enums\IncidentNotificationType;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Mail\SitePulseAlertMail;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\Monitor;
use App\Models\User;
use App\Support\SafeHttp\SafeHttpCheckResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Atomically persists bounded batches of completed monitor checks, incidents, TLS warnings, and outbox rows.
 */
final class CheckBatchPersister
{
    public const MAX_ERROR_MESSAGE_LENGTH = 255;

    public function __construct(
        private readonly MonitorStateTransition $stateTransition = new MonitorStateTransition,
    ) {}

    /**
     * Persist completed check outcomes, incident lifecycles, and notification outbox records atomically.
     *
     * @param  array<int, array{snapshot: MonitorProbeSnapshot, result: SafeHttpCheckResult, checkedAt: CarbonImmutable}>  $batch
     * @return array{
     *     persisted: int,
     *     staleDiscarded: int,
     *     succeeded: int,
     *     failed: int,
     *     incidentsOpened: int,
     *     incidentsResolved: int,
     *     notificationsQueued: int,
     *     tlsWarningsQueued: int
     * }
     */
    public function persistBatch(array $batch): array
    {
        if ($batch === []) {
            return [
                'persisted' => 0,
                'staleDiscarded' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'incidentsOpened' => 0,
                'incidentsResolved' => 0,
                'notificationsQueued' => 0,
                'tlsWarningsQueued' => 0,
            ];
        }

        $monitorIds = array_values(array_unique(array_map(
            static fn (array $item): int => $item['snapshot']->monitorId,
            $batch,
        )));
        sort($monitorIds);

        return DB::transaction(function () use ($batch, $monitorIds): array {
            $lockedMonitors = Monitor::query()
                ->whereIn('id', $monitorIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $openIncidentsByMonitor = Incident::query()
                ->whereIn('monitor_id', $monitorIds)
                ->where('status', IncidentStatus::OPEN->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('monitor_id');

            $ownerIds = $lockedMonitors
                ->pluck('user_id')
                ->unique()
                ->filter()
                ->values()
                ->all();

            $ownersById = User::query()
                ->whereIn('id', $ownerIds)
                ->where('is_owner', true)
                ->get()
                ->keyBy('id');

            $warningDays = max(1, (int) config('sitepulse.tls_expiry_warning_days', 14));

            $checkRows = [];
            $persisted = 0;
            $staleDiscarded = 0;
            $succeeded = 0;
            $failed = 0;
            $incidentsOpened = 0;
            $incidentsResolved = 0;
            $notificationsQueued = 0;
            $tlsWarningsQueued = 0;

            foreach ($batch as $entry) {
                /** @var MonitorProbeSnapshot $snapshot */
                $snapshot = $entry['snapshot'];
                /** @var SafeHttpCheckResult $result */
                $result = $entry['result'];
                /** @var CarbonImmutable $checkedAt */
                $checkedAt = $entry['checkedAt']->utc();

                /** @var Monitor|null $monitor */
                $monitor = $lockedMonitors->get($snapshot->monitorId);

                if ($monitor === null || ! $snapshot->matchesLockedMonitor($monitor)) {
                    $staleDiscarded++;

                    continue;
                }

                $previousStatus = $monitor->status;

                $transition = $this->stateTransition->apply(
                    currentStatus: $previousStatus,
                    currentFailures: (int) $monitor->consecutive_failures,
                    currentSuccesses: (int) $monitor->consecutive_successes,
                    checkSucceeded: $result->succeeded,
                );

                $formattedCheckedAt = $checkedAt->format('Y-m-d H:i:s');
                $updateAttributes = [
                    'status' => $transition->nextStatus->value,
                    'consecutive_failures' => $transition->consecutiveFailures,
                    'consecutive_successes' => $transition->consecutiveSuccesses,
                    'last_checked_at' => $formattedCheckedAt,
                    'next_check_at' => $checkedAt->addMinutes((int) $monitor->check_interval_minutes)->format('Y-m-d H:i:s'),
                    'last_status_code' => $result->statusCode,
                    'last_latency_ms' => max(0, $result->latencyMs),
                    'revision' => $snapshot->revision + 1,
                    'updated_at' => $formattedCheckedAt,
                ];

                $tlsWarningToQueue = null;

                if ($snapshot->scheme === 'https' && $result->tlsExpiresAt !== null) {
                    $tlsExpiresAt = CarbonImmutable::instance($result->tlsExpiresAt)->utc();
                    $updateAttributes['tls_expires_at'] = $tlsExpiresAt->format('Y-m-d H:i:s');
                    $updateAttributes['tls_last_checked_at'] = $formattedCheckedAt;

                    $warningCutoff = $checkedAt->addDays($warningDays);
                    $currentCycle = max(1, (int) ($monitor->tls_warning_cycle ?? 1));
                    $existingWarnedExpiry = $monitor->tls_warning_expires_at !== null
                        ? CarbonImmutable::instance($monitor->tls_warning_expires_at)->utc()
                        : null;

                    if ($tlsExpiresAt->lessThanOrEqualTo($warningCutoff)) {
                        if ($existingWarnedExpiry === null || $existingWarnedExpiry->getTimestamp() !== $tlsExpiresAt->getTimestamp()) {
                            $updateAttributes['tls_warning_expires_at'] = $tlsExpiresAt->format('Y-m-d H:i:s');
                            $tlsWarningToQueue = [
                                'expiresAt' => $tlsExpiresAt,
                                'cycle' => $currentCycle,
                            ];
                        }
                    } elseif ($existingWarnedExpiry !== null) {
                        // Certificate renewed beyond warning window: clear active warning and advance cycle to rearm cleanly.
                        $updateAttributes['tls_warning_expires_at'] = null;
                        $updateAttributes['tls_warning_cycle'] = $currentCycle + 1;
                    }
                } elseif ($snapshot->scheme === 'http') {
                    $updateAttributes['tls_expires_at'] = null;
                    $updateAttributes['tls_last_checked_at'] = null;
                    $updateAttributes['tls_warning_expires_at'] = null;
                }

                // Compare-and-swap against persisted revision prevents TOCTOU races on both SQLite and MySQL.
                $updatedRows = Monitor::query()
                    ->whereKey($monitor->getKey())
                    ->where('revision', $snapshot->revision)
                    ->where('is_active', true)
                    ->where('status', '!=', MonitorStatus::PAUSED->value)
                    ->update($updateAttributes);

                if ($updatedRows !== 1) {
                    $staleDiscarded++;

                    continue;
                }

                $snapshotCheckStatus = $result->succeeded ? CheckResultStatus::SUCCESS : CheckResultStatus::FAILURE;
                $sanitizedError = $result->succeeded
                    ? null
                    : self::sanitizeErrorMessage($result->errorMessage);

                $checkRows[] = [
                    'monitor_id' => $monitor->getKey(),
                    'status' => $snapshotCheckStatus->value,
                    'status_code' => $result->statusCode,
                    'latency_ms' => max(0, $result->latencyMs),
                    'failure_reason' => $result->failureReason?->value,
                    'error_message' => $sanitizedError,
                    'redirects_followed' => max(0, $result->redirectsFollowed),
                    'body_truncated' => $result->bodyTruncated,
                    'checked_at' => $formattedCheckedAt,
                ];

                $persisted++;
                if ($result->succeeded) {
                    $succeeded++;
                } else {
                    $failed++;
                }

                $owner = $ownersById->get((int) $monitor->user_id);
                $ownerEmail = ($owner instanceof User && is_string($owner->email) && trim($owner->email) !== '')
                    ? trim($owner->email)
                    : null;

                if (
                    MonitorStateTransition::isConfirmedOutage($previousStatus, $transition->nextStatus)
                    && ! $openIncidentsByMonitor->has($monitor->getKey())
                ) {
                    $incident = Incident::query()->create([
                        'monitor_id' => $monitor->getKey(),
                        'open_monitor_id' => $monitor->getKey(),
                        'started_at' => $checkedAt,
                        'resolved_at' => null,
                        'duration_seconds' => null,
                        'root_cause' => $sanitizedError ?? 'Endpoint check failed.',
                        'status' => IncidentStatus::OPEN,
                    ]);

                    $openIncidentsByMonitor->put($monitor->getKey(), $incident);
                    $incidentsOpened++;

                    if ($ownerEmail !== null) {
                        $queued = $this->queueNotification(
                            monitor: $monitor,
                            incident: $incident,
                            type: IncidentNotificationType::DOWN,
                            eventKey: "incident:{$incident->getKey()}:down",
                            recipientEmail: $ownerEmail,
                            errorSummary: $incident->root_cause,
                        );

                        if ($queued) {
                            $notificationsQueued++;
                        }
                    }
                } elseif (
                    MonitorStateTransition::isConfirmedRecovery($previousStatus, $transition->nextStatus)
                    && $openIncidentsByMonitor->has($monitor->getKey())
                ) {
                    /** @var Incident $openIncident */
                    $openIncident = $openIncidentsByMonitor->get($monitor->getKey());
                    $durationSeconds = $openIncident->started_at !== null
                        ? max(0, (int) $openIncident->started_at->utc()->diffInSeconds($checkedAt, false))
                        : 0;

                    $openIncident->forceFill([
                        'status' => IncidentStatus::RESOLVED,
                        'open_monitor_id' => null,
                        'resolved_at' => $checkedAt,
                        'duration_seconds' => $durationSeconds,
                    ])->save();

                    $openIncidentsByMonitor->forget($monitor->getKey());
                    $incidentsResolved++;

                    if ($ownerEmail !== null) {
                        $queued = $this->queueNotification(
                            monitor: $monitor,
                            incident: $openIncident,
                            type: IncidentNotificationType::RECOVERY,
                            eventKey: "incident:{$openIncident->getKey()}:recovery",
                            recipientEmail: $ownerEmail,
                            durationSeconds: $durationSeconds,
                        );

                        if ($queued) {
                            $notificationsQueued++;
                        }
                    }
                }

                if ($tlsWarningToQueue !== null && $ownerEmail !== null) {
                    /** @var CarbonImmutable $tlsExpiry */
                    $tlsExpiry = $tlsWarningToQueue['expiresAt'];
                    $tlsCycle = $tlsWarningToQueue['cycle'];

                    $queued = $this->queueNotification(
                        monitor: $monitor,
                        incident: null,
                        type: IncidentNotificationType::TLS_WARNING,
                        eventKey: "monitor:{$monitor->getKey()}:tls:c{$tlsCycle}:{$tlsExpiry->getTimestamp()}",
                        recipientEmail: $ownerEmail,
                        tlsExpiresAt: $tlsExpiry,
                    );

                    if ($queued) {
                        $notificationsQueued++;
                        $tlsWarningsQueued++;
                    }
                }
            }

            if ($checkRows !== []) {
                CheckResult::query()->insert($checkRows);
            }

            return [
                'persisted' => $persisted,
                'staleDiscarded' => $staleDiscarded,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'incidentsOpened' => $incidentsOpened,
                'incidentsResolved' => $incidentsResolved,
                'notificationsQueued' => $notificationsQueued,
                'tlsWarningsQueued' => $tlsWarningsQueued,
            ];
        });
    }

    /**
     * Enforce sanitized, control-character-free error messages capped at 255 characters.
     */
    public static function sanitizeErrorMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $cleaned = preg_replace('#(https?://)[^/@\s]+@#i', '$1[REDACTED]@', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\b(authorization|cookie|set-cookie|x-api-key)\s*:\s*(?:(?:bearer|basic|token)\s+)?\S+/i', '$1: [REDACTED]', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned)) ?? '';

        if ($cleaned === '') {
            return 'Check failed.';
        }

        if (mb_strlen($cleaned, 'UTF-8') > self::MAX_ERROR_MESSAGE_LENGTH) {
            return mb_substr($cleaned, 0, self::MAX_ERROR_MESSAGE_LENGTH - 3, 'UTF-8').'...';
        }

        return $cleaned;
    }

    private function queueNotification(
        Monitor $monitor,
        ?Incident $incident,
        IncidentNotificationType $type,
        string $eventKey,
        string $recipientEmail,
        ?string $errorSummary = null,
        ?int $durationSeconds = null,
        ?CarbonImmutable $tlsExpiresAt = null,
    ): bool {
        $notification = IncidentNotification::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'monitor_id' => $monitor->getKey(),
                'incident_id' => $incident?->getKey(),
                'type' => $type,
                'channel' => 'mail',
                'recipient' => $recipientEmail,
                'subject' => SitePulseAlertMail::formatSubject($type, (string) $monitor->name),
                'delivery_status' => NotificationDeliveryStatus::PENDING,
                'attempts' => 0,
                'is_success' => false,
                'error_summary' => $errorSummary,
                'duration_seconds' => $durationSeconds,
                'tls_expires_at' => $tlsExpiresAt,
            ],
        );

        return $notification->wasRecentlyCreated;
    }
}
