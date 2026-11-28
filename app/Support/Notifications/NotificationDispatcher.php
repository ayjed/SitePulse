<?php

namespace App\Support\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Mail\SitePulseAlertMail;
use App\Models\IncidentNotification;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitoring\CheckBatchPersister;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Dispatches durable incident notification outbox records strictly outside DB transactions and row locks.
 *
 * Security and concurrency invariants:
 * - Each claim atomically assigns a fresh cryptographic `claim_token` and increments fencing `claim_version`.
 * - Every post-claim completion or failure update checks `(id, delivery_status='sending', claim_token, claim_version)`
 *   so an expired stale worker can never overwrite a newer worker's terminal or active state.
 * - Pre-claim monotonic `hrtime(true)` deadline checks bound work per run; however, synchronous PHP mail/SMTP
 *   calls cannot be forcibly preempted mid-socket write and rely on the <= 5s transport timeout (`config/mail.php`).
 * - Crashes after remote SMTP acceptance and before fenced DB acknowledgement recover via `claim_timeout_seconds`,
 *   providing durable at-least-once delivery semantics rather than guaranteed exactly-once SMTP transmission.
 * - Stale `SENDING` rows whose `attempts` have already reached `notification_max_attempts` are reconciled atomically
 *   with claim-version fencing into terminal `FAILED` without sending another email or claiming definitive non-delivery.
 */
final class NotificationDispatcher
{
    public const EXECUTION_LOCK_KEY = 'sitepulse:dispatch-notifications:execution';

    public const STALE_EXHAUSTED_ERROR_MESSAGE = 'Final delivery attempt timed out during dispatch (at-least-once crash window: SMTP acceptance could not be confirmed).';

    /** @var Closure(): int */
    private readonly Closure $monotonicClockNs;

    /**
     * @param  (Closure(): int)|null  $monotonicClockNs
     */
    public function __construct(?Closure $monotonicClockNs = null)
    {
        $this->monotonicClockNs = $monotonicClockNs ?? static fn (): int => hrtime(true);
    }

    /**
     * Dispatch bounded pending, retryable failed, or stale timed-out notifications under an execution lock.
     *
     * @return array{
     *     alertsEnabled: bool,
     *     lockAcquired: bool,
     *     claimed: int,
     *     sent: int,
     *     failed: int,
     *     reconciled: int,
     *     fenced: int,
     *     deadlineReached: bool,
     *     elapsedMs: int
     * }
     */
    public function dispatchPending(?int $limit = null, int|float|null $deadlineSeconds = null): array
    {
        $startedAtNs = $this->nowNs();

        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Notification dispatch limit must be a positive integer.');
        }

        if ($deadlineSeconds !== null && $deadlineSeconds <= 0) {
            throw new InvalidArgumentException('Notification dispatch deadline must be positive.');
        }

        if (filter_var(config('sitepulse.alerts_enabled', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            return [
                'alertsEnabled' => false,
                'lockAcquired' => true,
                'claimed' => 0,
                'sent' => 0,
                'failed' => 0,
                'reconciled' => 0,
                'fenced' => 0,
                'deadlineReached' => false,
                'elapsedMs' => $this->elapsedMs($startedAtNs),
            ];
        }

        $maxLimitCeiling = max(1, (int) config('sitepulse.notification_max_limit', 100));
        $configuredLimit = $limit ?? (int) config('sitepulse.notification_batch_limit', 25);
        $effectiveLimit = max(1, min($maxLimitCeiling, $configuredLimit));

        $maxDeadlineCeiling = max(1, (int) config('sitepulse.notification_max_deadline_seconds', 55));
        $configuredDeadline = $deadlineSeconds ?? (int) config('sitepulse.notification_deadline_seconds', 25);
        $effectiveDeadlineSeconds = min($maxDeadlineCeiling, max(0.001, (float) $configuredDeadline));
        $deadlineNs = $startedAtNs + (int) round($effectiveDeadlineSeconds * 1_000_000_000);

        $lockTtlSeconds = max(30, (int) config('sitepulse.notification_lock_ttl_seconds', 300));
        $lockStore = config('sitepulse.lock_store');
        $lock = Cache::store(is_string($lockStore) && $lockStore !== '' ? $lockStore : null)
            ->lock(self::EXECUTION_LOCK_KEY, $lockTtlSeconds);

        if (! $lock->get()) {
            $elapsedMs = $this->elapsedMs($startedAtNs);
            Log::info('SitePulse notification dispatch skipped because an execution lock is already active.', [
                'lock_acquired' => false,
                'elapsed_ms' => $elapsedMs,
            ]);

            return [
                'alertsEnabled' => true,
                'lockAcquired' => false,
                'claimed' => 0,
                'sent' => 0,
                'failed' => 0,
                'reconciled' => 0,
                'fenced' => 0,
                'deadlineReached' => false,
                'elapsedMs' => $elapsedMs,
            ];
        }

        $maxAttempts = max(1, (int) config('sitepulse.notification_max_attempts', 3));
        $claimTimeoutSeconds = max(15, (int) config('sitepulse.notification_claim_timeout_seconds', 60));
        $baseBackoffSeconds = max(5, (int) config('sitepulse.notification_retry_backoff_seconds', 60));

        $claimedCount = 0;
        $sentCount = 0;
        $failedCount = 0;
        $reconciledCount = 0;
        $fencedCount = 0;
        $deadlineReached = false;

        try {
            $now = CarbonImmutable::instance(now('UTC'))->utc();
            $nowFormatted = $now->format('Y-m-d H:i:s');
            $staleClaimCutoffFormatted = $now->subSeconds($claimTimeoutSeconds)->format('Y-m-d H:i:s');

            $reconcileOutcome = $this->reconcileStaleExhaustedClaims(
                limit: $effectiveLimit,
                maxAttempts: $maxAttempts,
                claimTimeoutSeconds: $claimTimeoutSeconds,
                staleClaimCutoffFormatted: $staleClaimCutoffFormatted,
                deadlineNs: $deadlineNs,
            );
            $reconciledCount = $reconcileOutcome['reconciled'];
            $fencedCount += $reconcileOutcome['fenced'];
            if ($reconcileOutcome['deadlineReached']) {
                $deadlineReached = true;
            }

            $remainingLimit = $effectiveLimit - $reconciledCount;
            $candidateIds = ($remainingLimit > 0 && ! $deadlineReached)
                ? IncidentNotification::query()
                    ->where(function (Builder $query) use ($nowFormatted, $staleClaimCutoffFormatted, $maxAttempts): void {
                        $this->applyClaimableFilter($query, $nowFormatted, $staleClaimCutoffFormatted, $maxAttempts);
                    })
                    ->orderBy('id')
                    ->limit($remainingLimit)
                    ->pluck('id')
                    ->all()
                : [];

            foreach ($candidateIds as $notificationId) {
                if ($this->nowNs() >= $deadlineNs) {
                    $deadlineReached = true;

                    break;
                }

                $claimTimestamp = CarbonImmutable::instance(now('UTC'))->utc();
                $claimNowFormatted = $claimTimestamp->format('Y-m-d H:i:s');
                $claimStaleFormatted = $claimTimestamp->subSeconds($claimTimeoutSeconds)->format('Y-m-d H:i:s');
                $claimToken = bin2hex(random_bytes(32));

                $claimed = IncidentNotification::query()
                    ->whereKey($notificationId)
                    ->where(function (Builder $query) use ($claimNowFormatted, $claimStaleFormatted, $maxAttempts): void {
                        $this->applyClaimableFilter($query, $claimNowFormatted, $claimStaleFormatted, $maxAttempts);
                    })
                    ->update([
                        'delivery_status' => NotificationDeliveryStatus::SENDING->value,
                        'attempts' => DB::raw('attempts + 1'),
                        'claimed_at' => $claimNowFormatted,
                        'claim_token' => $claimToken,
                        'claim_version' => DB::raw('claim_version + 1'),
                        'updated_at' => $claimNowFormatted,
                    ]);

                if ($claimed !== 1) {
                    continue;
                }

                /** @var IncidentNotification|null $notification */
                $notification = IncidentNotification::query()
                    ->with(['monitor.user', 'incident'])
                    ->find($notificationId);

                if ($notification === null || $notification->claim_token !== $claimToken) {
                    $fencedCount++;

                    continue;
                }

                $ownedClaimVersion = (int) $notification->claim_version;
                $claimedCount++;

                $monitor = $notification->monitor;
                $owner = $monitor?->user;

                if (! $monitor instanceof Monitor || ! $owner instanceof User || ! $owner->isOwner() || trim((string) $owner->email) === '') {
                    $fenced = ! $this->markPermanentFailure(
                        notification: $notification,
                        claimToken: $claimToken,
                        claimVersion: $ownedClaimVersion,
                        message: 'No verified site owner recipient is available.',
                    );

                    if ($fenced) {
                        $fencedCount++;
                    } else {
                        $failedCount++;
                    }

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
                    // External mail transport executes strictly outside DB transactions and row locks.
                    Mail::to($recipientEmail)->send($mailable);

                    $sentAtFormatted = CarbonImmutable::instance(now('UTC'))->utc()->format('Y-m-d H:i:s');

                    $updated = $this->fencedUpdate($notificationId, $claimToken, $ownedClaimVersion, [
                        'recipient' => $recipientEmail,
                        'delivery_status' => NotificationDeliveryStatus::SENT->value,
                        'claimed_at' => null,
                        'claim_token' => null,
                        'next_attempt_at' => null,
                        'sent_at' => $sentAtFormatted,
                        'is_success' => true,
                        'error_message' => null,
                        'updated_at' => $sentAtFormatted,
                    ]);

                    if ($updated === 1) {
                        $sentCount++;
                    } else {
                        $fencedCount++;
                        Log::warning('SitePulse stale notification worker lost claim ownership on delivery completion.', [
                            'notification_id' => $notificationId,
                            'claim_version' => $ownedClaimVersion,
                        ]);
                    }
                } catch (Throwable $exception) {
                    $attemptNumber = max(1, (int) $notification->attempts);
                    $backoffSeconds = $baseBackoffSeconds * (2 ** max(0, $attemptNumber - 1));
                    $failedAt = CarbonImmutable::instance(now('UTC'))->utc();
                    $nextAttemptAtFormatted = $attemptNumber < $maxAttempts
                        ? $failedAt->addSeconds($backoffSeconds)->format('Y-m-d H:i:s')
                        : null;
                    $sanitizedError = CheckBatchPersister::sanitizeErrorMessage($exception->getMessage());

                    $updated = $this->fencedUpdate($notificationId, $claimToken, $ownedClaimVersion, [
                        'delivery_status' => NotificationDeliveryStatus::FAILED->value,
                        'claimed_at' => null,
                        'claim_token' => null,
                        'next_attempt_at' => $nextAttemptAtFormatted,
                        'sent_at' => null,
                        'is_success' => false,
                        'error_message' => $sanitizedError,
                        'updated_at' => $failedAt->format('Y-m-d H:i:s'),
                    ]);

                    if ($updated === 1) {
                        Log::warning('SitePulse alert notification delivery failed.', [
                            'notification_id' => $notificationId,
                            'monitor_id' => $notification->monitor_id,
                            'type' => $notification->type->value,
                            'attempts' => $attemptNumber,
                            'max_attempts' => $maxAttempts,
                            'error' => $sanitizedError,
                        ]);

                        $failedCount++;
                    } else {
                        $fencedCount++;
                        Log::warning('SitePulse stale notification worker lost claim ownership on failure update.', [
                            'notification_id' => $notificationId,
                            'claim_version' => $ownedClaimVersion,
                        ]);
                    }
                }
            }

            if ($this->nowNs() >= $deadlineNs && $candidateIds !== []) {
                $deadlineReached = true;
            }

            $elapsedMs = $this->elapsedMs($startedAtNs);

            Log::info('SitePulse notification dispatch cycle finished.', [
                'claimed' => $claimedCount,
                'sent' => $sentCount,
                'failed' => $failedCount,
                'reconciled' => $reconciledCount,
                'fenced' => $fencedCount,
                'deadline_reached' => $deadlineReached,
                'elapsed_ms' => $elapsedMs,
            ]);

            return [
                'alertsEnabled' => true,
                'lockAcquired' => true,
                'claimed' => $claimedCount,
                'sent' => $sentCount,
                'failed' => $failedCount,
                'reconciled' => $reconciledCount,
                'fenced' => $fencedCount,
                'deadlineReached' => $deadlineReached,
                'elapsedMs' => $elapsedMs,
            ];
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Best-effort unlock; TTL guarantees automatic expiration on abrupt termination.
            }
        }
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

    private function markPermanentFailure(
        IncidentNotification $notification,
        string $claimToken,
        int $claimVersion,
        string $message,
    ): bool {
        $maxAttempts = max(1, (int) config('sitepulse.notification_max_attempts', 3));
        $nowFormatted = CarbonImmutable::instance(now('UTC'))->utc()->format('Y-m-d H:i:s');

        $updated = $this->fencedUpdate((int) $notification->getKey(), $claimToken, $claimVersion, [
            'delivery_status' => NotificationDeliveryStatus::FAILED->value,
            'attempts' => max((int) $notification->attempts, $maxAttempts),
            'claimed_at' => null,
            'claim_token' => null,
            'next_attempt_at' => null,
            'sent_at' => null,
            'is_success' => false,
            'error_message' => CheckBatchPersister::sanitizeErrorMessage($message),
            'updated_at' => $nowFormatted,
        ]);

        return $updated === 1;
    }

    /**
     * Update a claimed notification only while this worker still holds exact fencing ownership.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fencedUpdate(int $notificationId, string $claimToken, int $claimVersion, array $attributes): int
    {
        return IncidentNotification::query()
            ->whereKey($notificationId)
            ->where('delivery_status', NotificationDeliveryStatus::SENDING->value)
            ->where('claim_token', $claimToken)
            ->where('claim_version', $claimVersion)
            ->update($attributes);
    }

    /**
     * Atomically finalize stale SENDING notifications that have already exhausted retry attempts.
     *
     * Transitions expired final-attempt claims to FAILED without sending another email or altering
     * attempts, fenced by (id, delivery_status='sending', claim_token, claim_version, attempts>=max).
     *
     * @return array{reconciled: int, fenced: int, deadlineReached: bool}
     */
    private function reconcileStaleExhaustedClaims(
        int $limit,
        int $maxAttempts,
        int $claimTimeoutSeconds,
        string $staleClaimCutoffFormatted,
        int $deadlineNs,
    ): array {
        $candidates = IncidentNotification::query()
            ->where('delivery_status', NotificationDeliveryStatus::SENDING->value)
            ->where('attempts', '>=', $maxAttempts)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', $staleClaimCutoffFormatted)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'monitor_id', 'type', 'attempts', 'claim_token', 'claim_version']);

        $reconciled = 0;
        $fenced = 0;
        $deadlineReached = false;
        $sanitizedMessage = CheckBatchPersister::sanitizeErrorMessage(self::STALE_EXHAUSTED_ERROR_MESSAGE);

        foreach ($candidates as $candidate) {
            if ($this->nowNs() >= $deadlineNs) {
                $deadlineReached = true;

                break;
            }

            $now = CarbonImmutable::instance(now('UTC'))->utc();
            $nowFormatted = $now->format('Y-m-d H:i:s');
            $staleCutoffFormatted = $now->subSeconds($claimTimeoutSeconds)->format('Y-m-d H:i:s');

            $updateQuery = IncidentNotification::query()
                ->whereKey((int) $candidate->id)
                ->where('delivery_status', NotificationDeliveryStatus::SENDING->value)
                ->where('attempts', '>=', $maxAttempts)
                ->whereNotNull('claimed_at')
                ->where('claimed_at', '<=', $staleCutoffFormatted)
                ->where('claim_version', (int) $candidate->claim_version);

            if ($candidate->claim_token === null) {
                $updateQuery->whereNull('claim_token');
            } else {
                $updateQuery->where('claim_token', $candidate->claim_token);
            }

            $updated = $updateQuery->update([
                'delivery_status' => NotificationDeliveryStatus::FAILED->value,
                'claimed_at' => null,
                'claim_token' => null,
                'claim_version' => DB::raw('claim_version + 1'),
                'next_attempt_at' => null,
                'sent_at' => null,
                'is_success' => false,
                'error_message' => $sanitizedMessage,
                'updated_at' => $nowFormatted,
            ]);

            if ($updated === 1) {
                $reconciled++;
                Log::warning('SitePulse reconciled stale exhausted notification claim into terminal failed state.', [
                    'notification_id' => (int) $candidate->id,
                    'monitor_id' => $candidate->monitor_id,
                    'type' => $candidate->type->value,
                    'attempts' => (int) $candidate->attempts,
                    'claim_version' => (int) $candidate->claim_version + 1,
                ]);
            } else {
                $fenced++;
            }
        }

        return [
            'reconciled' => $reconciled,
            'fenced' => $fenced,
            'deadlineReached' => $deadlineReached,
        ];
    }

    private function nowNs(): int
    {
        return ($this->monotonicClockNs)();
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return max(0, (int) round(($this->nowNs() - $startedAtNs) / 1_000_000));
    }
}
