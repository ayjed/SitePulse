<?php

namespace App\Support\Dashboard;

use App\Enums\CheckResultStatus;
use App\Enums\IncidentStatus;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class MonitorHistory
{
    private const PAGE_SIZE = 25;

    private const LATENCY_SAMPLE_LIMIT = 60;

    /**
     * Count recorded outcomes in (cutoff, asOf] using one indexed aggregate.
     *
     * @return array{asOf: CarbonImmutable, windows: array<string, array{sampleCount: int, successfulCount: int, percentage: ?float}>}
     */
    public function uptime(int $monitorId, ?DateTimeInterface $asOf = null): array
    {
        $asOf = $this->referenceTime($asOf);
        $cutoffs = ['24h' => $asOf->subHours(24), '7d' => $asOf->subDays(7), '30d' => $asOf->subDays(30)];
        $completed = $this->monitor($monitorId)->checkResults()
            ->where('checked_at', '>', $cutoffs['30d'])
            ->where('checked_at', '<=', $asOf)
            ->whereIn('status', [CheckResultStatus::SUCCESS->value, CheckResultStatus::FAILURE->value])
            ->toBase();

        foreach ($cutoffs as $window => $cutoff) {
            $completed->selectRaw(
                "COUNT(CASE WHEN checked_at > ? THEN 1 END) AS samples_{$window}, "
                ."COUNT(CASE WHEN checked_at > ? AND status = ? THEN 1 END) AS successes_{$window}",
                [$cutoff, $cutoff, CheckResultStatus::SUCCESS->value],
            );
        }

        $counts = $completed->first();
        $windows = [];
        foreach (array_keys($cutoffs) as $window) {
            $samples = (int) $counts->{"samples_{$window}"};
            $successes = (int) $counts->{"successes_{$window}"};
            $windows[$window] = [
                'sampleCount' => $samples,
                'successfulCount' => $successes,
                'percentage' => $samples === 0 ? null : ($successes / $samples) * 100.0,
            ];
        }

        return ['asOf' => $asOf, 'windows' => $windows];
    }

    /**
     * @param  ?Cursor  $cursor  A cursor from another page of this query.
     * @return CursorPaginator<int, CheckResult>
     */
    public function checks(int $monitorId, ?Cursor $cursor = null, ?DateTimeInterface $asOf = null): CursorPaginator
    {
        $checks = $this->historyRange($this->monitor($monitorId)->checkResults(), 'checked_at', $asOf, $cursor);

        return $checks->cursorPaginate(
            self::PAGE_SIZE,
            ['id', 'checked_at', 'status', 'status_code', 'latency_ms', 'failure_reason'],
            'check_cursor',
            $cursor ?? '',
        );
    }

    /** @return Collection<int, CheckResult> */
    public function latencySamples(int $monitorId, ?DateTimeInterface $asOf = null): Collection
    {
        return $this->historyRange($this->monitor($monitorId)->checkResults(), 'checked_at', $asOf)
            ->limit(self::LATENCY_SAMPLE_LIMIT)
            ->get(['id', 'checked_at', 'latency_ms']);
    }

    /**
     * @param  ?Cursor  $cursor  A cursor from another page of this query with the same status filter.
     * @return CursorPaginator<int, Incident>
     */
    public function incidents(
        int $monitorId,
        ?IncidentStatus $status = null,
        ?Cursor $cursor = null,
        ?DateTimeInterface $asOf = null,
    ): CursorPaginator {
        $incidents = $this->historyRange($this->monitor($monitorId)->incidents(), 'started_at', $asOf, $cursor);

        if ($status !== null) {
            $incidents->where('status', $status->value);
        }

        return $incidents->cursorPaginate(
            self::PAGE_SIZE,
            ['id', 'started_at', 'resolved_at', 'duration_seconds', 'root_cause', 'status'],
            'incident_cursor',
            $cursor ?? '',
        );
    }

    /**
     * @template T of CheckResult|Incident
     *
     * @param  HasMany<T, Monitor>  $query
     * @return HasMany<T, Monitor>
     */
    private function historyRange(
        HasMany $query,
        string $column,
        ?DateTimeInterface $asOf,
        ?Cursor $cursor = null,
    ): HasMany {
        $latest = $this->referenceTime($asOf)->toDateTimeString();
        if ($cursor !== null) {
            // Choose an index range directly. Laravel's cursor still applies the exact timestamp and ID tie-break.
            $anchor = $cursor->parameter($column);
            if ($cursor->pointsToNextItems()) {
                $latest = min($latest, $anchor);
            } else {
                $query->where($column, '>=', $anchor);
            }
        }

        return $query->where($column, '<=', $latest)->orderByDesc($column)->orderByDesc('id');
    }

    private function monitor(int $monitorId): Monitor
    {
        Gate::authorize('viewAny', Monitor::class);
        $monitor = Monitor::query()->forOwner(Auth::user())->findOrFail($monitorId, ['id', 'user_id']);
        Gate::authorize('view', $monitor);

        return $monitor;
    }

    private function referenceTime(?DateTimeInterface $asOf): CarbonImmutable
    {
        return CarbonImmutable::instance($asOf ?? now('UTC'))->utc()->startOfSecond();
    }
}
