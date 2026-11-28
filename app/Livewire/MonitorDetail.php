<?php

namespace App\Livewire;

use App\Enums\IncidentStatus;
use App\Models\Monitor;
use App\Support\Dashboard\DetailCursor;
use App\Support\Dashboard\MonitorHistory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class MonitorDetail extends Component
{
    #[Locked]
    public Monitor $monitor;

    #[Locked]
    public string $asOf;

    #[Locked]
    public string $checkCursor = '';

    #[Locked]
    public string $incidentCursor = '';

    #[Locked]
    public string $incidentFilter = 'all';

    public function boot(): void
    {
        Gate::authorize('viewAny', Monitor::class);
    }

    public function mount(Monitor $monitor): void
    {
        Gate::authorize('view', $monitor);
        $this->monitor = $monitor;
        $this->refreshSnapshot();
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->monitor);
    }

    public function refreshSnapshot(): void
    {
        $this->asOf = now('UTC')->toDateTimeString();
        $this->checkCursor = '';
        $this->incidentCursor = '';
    }

    public function showChecks(mixed $token = null): void
    {
        $this->checkCursor = DetailCursor::accept($token, 'checked_at', $this->asOf, $this->scope('checks'));
    }

    public function showIncidents(mixed $token = null): void
    {
        $this->incidentCursor = DetailCursor::accept($token, 'started_at', $this->asOf, $this->scope('incidents'));
    }

    public function filterIncidents(mixed $filter): void
    {
        if (is_string($filter) && $filter !== $this->incidentFilter
            && ($filter === 'all' || IncidentStatus::tryFrom($filter) !== null)) {
            $this->incidentFilter = $filter;
            $this->incidentCursor = '';
        }
    }

    public function render(): View
    {
        $asOf = CarbonImmutable::parse($this->asOf, 'UTC');
        $data = ['monitor' => $this->monitor, 'reference' => $asOf, 'history' => null];

        try {
            $history = app(MonitorHistory::class);
            $id = $this->monitor->id;
            $uptime = $history->uptime($id, $asOf)['windows'];
            $checks = $history->checks($id, Cursor::fromEncoded($this->checkCursor), $asOf);
            $incidents = $history->incidents(
                $id, IncidentStatus::tryFrom($this->incidentFilter), Cursor::fromEncoded($this->incidentCursor), $asOf,
            );

            $data['history'] = [
                'uptime' => $uptime,
                'latencies' => $history->latencySamples($id, $asOf),
                'checks' => $checks,
                'checkPrevious' => DetailCursor::issue($checks->previousCursor(), $this->scope('checks')),
                'checkNext' => DetailCursor::issue($checks->nextCursor(), $this->scope('checks')),
                'incidents' => $incidents,
                'incidentPrevious' => DetailCursor::issue($incidents->previousCursor(), $this->scope('incidents')),
                'incidentNext' => DetailCursor::issue($incidents->nextCursor(), $this->scope('incidents')),
            ];
        } catch (QueryException) {
            Log::warning('Unable to load the monitor history.');
        }

        return view('livewire.monitor-detail', $data);
    }

    private function scope(string $history): string
    {
        $filter = $history === 'incidents' ? $this->incidentFilter : 'all';

        return "{$this->monitor->id}|$this->asOf|$history|$filter";
    }
}
