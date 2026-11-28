<?php

namespace App\Support\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Mail\SitePulseAlertMail;
use App\Models\IncidentNotification;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitoring\CheckBatchPersister;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Dispatches durable incident notification outbox records strictly outside DB transactions and row locks.
 */
final class NotificationDispatcher
{
    /**
     * Dispatch up to $limit due pending, retryable failed, or stale claimed notifications.
     *
     * Uses short per-row atomic claim updates before Mail delivery so SMTP waits never hold DB locks.
     * Note: if a process crashes after SMTP acceptance before final DB state update, stale claim recovery
     * provides at-least-once delivery rather than guaranteed exactly-once external email transmission.
     *
     * @return array{alertsEnabled: bool, claimed: int, sent: int, failed: int}
     */
    public function dispatchPending(int $limit = 25): array
    {
        if (filter_var(config('sitepulse.alerts_enabled', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            return [
                'alertsEnabled' => false,
                'claimed' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $effectiveLimit = max(1, min(100, $limit));
        $maxAttempts = max(1, (int) config('sitepulse.notification_max_attempts', 3));
        $claimTimeoutSeconds = max(15, (int) config('sitepulse.notification_claim_timeout_seconds', 60));
        $baseBackoffSeconds = max(5, (int) config('sitepulse.notification_retry_backoff_seconds', 60));

        $now = CarbonImmutable::instance(now('UTC'))->utc();
        $nowFormatted = $now->format('Y-m-d H:i:s');
        $staleClaimCutoffFormatted = $now->subSeconds($claimTimeoutSeconds)->format('Y-m-d H:i:s');

        $candidateIds = IncidentNotification::query()
            ->where(function (Builder $query) use ($nowFormatted, $staleClaimCutoffFormatted, $maxAttempts): void {
                $this->applyClaimableFilter($query, $nowFormatted, $staleClaimCutoffFormatted, $maxAttempts);
            })
            ->orderBy('id')
            ->limit($effectiveLimit)
            ->pluck('id')
            ->all();

        $claimedCount = 0;
        $sentCount = 0;
        $failedCount = 0;

        foreach ($candidateIds as $notificationId) {
            $claimTimestamp = CarbonImmutable::instance(now('UTC'))->utc();
            $claimNowFormatted = $claimTimestamp->format('Y-m-d H:i:s');
            $claimStaleFormatted = $claimTimestamp->subSeconds($claimTimeoutSeconds)->format('Y-m-d H:i:s');

            $claimed = IncidentNotification::query()
                ->whereKey($notificationId)
                ->where(function (Builder $query) use ($claimNowFormatted, $claimStaleFormatted, $maxAttempts): void {
                    $this->applyClaimableFilter($query, $claimNowFormatted, $claimStaleFormatted, $maxAttempts);
                })
                ->update([
                    'delivery_status' => NotificationDeliveryStatus::SENDING->value,
                    'attempts' => DB::raw('attempts + 1'),
                    'claimed_at' => $claimNowFormatted,
                    'updated_at' => $claimNowFormatted,
                ]);

            if ($claimed !== 1) {
                continue;
            }

            $claimedCount++;

            /** @var IncidentNotification|null $notification */
            $notification = IncidentNotification::query()
                ->with(['monitor.user', 'incident'])
                ->find($notificationId);

            if ($notification === null) {
                continue;
            }

            $monitor = $notification->monitor;
            $owner = $monitor?->user;

            if (! $monitor instanceof Monitor || ! $owner instanceof User || ! $owner->isOwner() || trim((string) $owner->email) === '') {
                $this->markPermanentFailure($notification, 'No verified site owner recipient is available.');
                $failedCount++;

                continue;
            }

            // Always resolve target recipient server-side from the verified single owner account.
            $recipientEmail = trim((string) $owner->email);
            $occurredAt = $notification->created_at !== null
                ? CarbonImmutable::instance($notification->created_at)->utc()
                : $claimTimestamp;
            $tlsExpiresAt = $notification->tls_expires_at !== null
                ? CarbonImmutable::instance($notification->tls_expires_at)->utc()
                : null;
            $summary = $notification->error_summary ?? $notification->incident?->root_cause;
            $durationSeconds = $notification->duration_seconds ?? $notification->incident?->duration_seconds;

            $mailable = new SitePulseAlertMail(
                alertType: $notification->type,
                monitorName: (string) $monitor->name,
                monitorUrl: (string) $monitor->url,
                occurredAt: $occurredAt,
                summary: $summary,
                durationSeconds: $durationSeconds,
                tlsExpiresAt: $tlsExpiresAt,
            );

            try {
                Mail::to($recipientEmail)->send($mailable);

                $sentAtFormatted = CarbonImmutable::instance(now('UTC'))->utc()->format('Y-m-d H:i:s');

                IncidentNotification::query()
                    ->whereKey($notificationId)
                    ->update([
                        'recipient' => $recipientEmail,
                        'delivery_status' => NotificationDeliveryStatus::SENT->value,
                        'claimed_at' => null,
                        'next_attempt_at' => null,
                        'sent_at' => $sentAtFormatted,
                        'is_success' => true,
                        'error_message' => null,
                        'updated_at' => $sentAtFormatted,
                    ]);

                $sentCount++;
            } catch (Throwable $exception) {
                $attemptNumber = max(1, (int) $notification->attempts);
                $backoffSeconds = $baseBackoffSeconds * (2 ** max(0, $attemptNumber - 1));
                $failedAt = CarbonImmutable::instance(now('UTC'))->utc();
                $nextAttemptAtFormatted = $attemptNumber < $maxAttempts
                    ? $failedAt->addSeconds($backoffSeconds)->format('Y-m-d H:i:s')
                    : null;
                $sanitizedError = CheckBatchPersister::sanitizeErrorMessage($exception->getMessage());

                IncidentNotification::query()
                    ->whereKey($notificationId)
                    ->update([
                        'delivery_status' => NotificationDeliveryStatus::FAILED->value,
                        'claimed_at' => null,
                        'next_attempt_at' => $nextAttemptAtFormatted,
                        'sent_at' => null,
                        'is_success' => false,
                        'error_message' => $sanitizedError,
                        'updated_at' => $failedAt->format('Y-m-d H:i:s'),
                    ]);

                Log::warning('SitePulse alert notification delivery failed.', [
                    'notification_id' => $notificationId,
                    'monitor_id' => $notification->monitor_id,
                    'type' => $notification->type->value,
                    'attempts' => $attemptNumber,
                    'max_attempts' => $maxAttempts,
                    'error' => $sanitizedError,
                ]);

                $failedCount++;
            }
        }

        return [
            'alertsEnabled' => true,
            'claimed' => $claimedCount,
            'sent' => $sentCount,
            'failed' => $failedCount,
        ];
    }

    /**
     * Restrict candidate query cleanly to due pending, retryable failed, or timed-out sending rows.
     *
     * @param  Builder<IncidentNotification>  $query
     */
    private function applyClaimableFilter(
        Builder $query,
        string $nowFormatted,
        string $staleClaimCutoffFormatted,
        int $maxAttempts,
    ): void {
        $query->where('attempts', '<', $maxAttempts)
            ->where(function (Builder $stateQuery) use ($nowFormatted, $staleClaimCutoffFormatted): void {
                $stateQuery->where(function (Builder $pending) use ($nowFormatted): void {
                    $pending->where('delivery_status', NotificationDeliveryStatus::PENDING->value)
                        ->where(function (Builder $due) use ($nowFormatted): void {
                            $due->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', $nowFormatted);
                        });
                })->orWhere(function (Builder $failed) use ($nowFormatted): void {
                    $failed->where('delivery_status', NotificationDeliveryStatus::FAILED->value)
                        ->where(function (Builder $due) use ($nowFormatted): void {
                            $due->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', $nowFormatted);
                        });
                })->orWhere(function (Builder $staleSending) use ($staleClaimCutoffFormatted): void {
                    $staleSending->where('delivery_status', NotificationDeliveryStatus::SENDING->value)
                        ->whereNotNull('claimed_at')
                        ->where('claimed_at', '<=', $staleClaimCutoffFormatted);
                });
            });
    }

    private function markPermanentFailure(IncidentNotification $notification, string $message): void
    {
        $maxAttempts = max(1, (int) config('sitepulse.notification_max_attempts', 3));
        $nowFormatted = CarbonImmutable::instance(now('UTC'))->utc()->format('Y-m-d H:i:s');

        IncidentNotification::query()
            ->whereKey($notification->getKey())
            ->update([
                'delivery_status' => NotificationDeliveryStatus::FAILED->value,
                'attempts' => max((int) $notification->attempts, $maxAttempts),
                'claimed_at' => null,
                'next_attempt_at' => null,
                'sent_at' => null,
                'is_success' => false,
                'error_message' => CheckBatchPersister::sanitizeErrorMessage($message),
                'updated_at' => $nowFormatted,
            ]);
    }
}
