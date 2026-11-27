<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Http\Requests\MonitorConfigurationRequest;
use App\Models\Monitor;
use App\Support\SafeHttp\SafeHttpClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Manages single-owner HTTP/HTTPS monitor configuration and pause/resume transitions without live network probes.
 */
class MonitorController extends Controller
{
    public function __construct(
        private readonly SafeHttpClient $safeHttpClient,
    ) {}

    /**
     * List all monitors owned by the authenticated site owner.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Monitor::class);

        $monitors = Monitor::query()
            ->forOwner($request->user())
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return view('monitors.index', [
            'owner' => $request->user(),
            'monitors' => $monitors,
        ]);
    }

    /**
     * Show the create monitor configuration form.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', Monitor::class);

        return view('monitors.create', [
            'owner' => $request->user(),
            'monitor' => new Monitor([
                'check_interval_minutes' => Monitor::DEFAULT_INTERVAL_MINUTES,
                'timeout_seconds' => Monitor::DEFAULT_TIMEOUT_SECONDS,
                'expected_status_code_min' => Monitor::DEFAULT_EXPECTED_STATUS_MIN,
                'expected_status_code_max' => Monitor::DEFAULT_EXPECTED_STATUS_MAX,
            ]),
            'allowedIntervals' => Monitor::ALLOWED_INTERVALS,
        ]);
    }

    /**
     * Persist a newly validated monitor bound explicitly to the authenticated owner.
     */
    public function store(MonitorConfigurationRequest $request): RedirectResponse
    {
        Gate::authorize('create', Monitor::class);

        $monitor = new Monitor($request->normalizedConfiguration($this->safeHttpClient));
        $monitor->user()->associate($request->user());

        $isActive = $request->desiredActiveState(default: true);
        $monitor->is_active = $isActive;
        $monitor->status = $isActive ? MonitorStatus::UNKNOWN : MonitorStatus::PAUSED;
        $monitor->consecutive_failures = 0;
        $monitor->consecutive_successes = 0;
        $monitor->save();

        return redirect()
            ->route('monitors.show', $monitor)
            ->with('status', 'Monitor created.');
    }

    /**
     * Show read-only configuration and current monitoring state for an owner's monitor.
     */
    public function show(Request $request, Monitor $monitor): View
    {
        Gate::authorize('view', $monitor);

        return view('monitors.show', [
            'owner' => $request->user(),
            'monitor' => $monitor,
        ]);
    }

    /**
     * Show the monitor configuration edit form.
     */
    public function edit(Request $request, Monitor $monitor): View
    {
        Gate::authorize('update', $monitor);

        return view('monitors.edit', [
            'owner' => $request->user(),
            'monitor' => $monitor,
            'allowedIntervals' => Monitor::ALLOWED_INTERVALS,
        ]);
    }

    /**
     * Update monitor configuration safely while preserving or transitioning state predictably.
     */
    public function update(MonitorConfigurationRequest $request, Monitor $monitor): RedirectResponse
    {
        Gate::authorize('update', $monitor);

        $previousUrl = $monitor->url;
        $previousInterval = $monitor->check_interval_minutes;
        $wasActive = (bool) $monitor->is_active;

        $normalized = $request->normalizedConfiguration($this->safeHttpClient);
        $desiredActive = $request->desiredActiveState(default: $wasActive);

        $monitor->fill($normalized);

        if ($monitor->url !== $previousUrl) {
            $monitor->resetTelemetryForUrlChange($desiredActive);
            $monitor->save();
        } else {
            if ($monitor->check_interval_minutes !== $previousInterval && $monitor->last_checked_at !== null && $desiredActive) {
                $monitor->next_check_at = $monitor->last_checked_at->copy()->addMinutes($monitor->check_interval_minutes);
            }

            if ($desiredActive !== $wasActive) {
                if ($desiredActive) {
                    $monitor->resume();
                } else {
                    $monitor->pause();
                }
            } else {
                $monitor->save();
            }
        }

        return redirect()
            ->route('monitors.show', $monitor)
            ->with('status', 'Monitor updated.');
    }

    /**
     * Delete an owner's monitor configuration permanently.
     */
    public function destroy(Monitor $monitor): RedirectResponse
    {
        Gate::authorize('delete', $monitor);

        $monitor->delete();

        return redirect()
            ->route('monitors.index')
            ->with('status', 'Monitor deleted.');
    }

    /**
     * Pause active monitoring for this monitor without deleting history.
     */
    public function pause(Monitor $monitor): RedirectResponse
    {
        Gate::authorize('pause', $monitor);

        $monitor->pause();

        return redirect()
            ->back(fallback: route('monitors.show', $monitor))
            ->with('status', 'Monitor paused.');
    }

    /**
     * Resume monitoring eligibility without triggering immediate outbound HTTP probes.
     */
    public function resume(Monitor $monitor): RedirectResponse
    {
        Gate::authorize('resume', $monitor);

        $monitor->resume();

        return redirect()
            ->back(fallback: route('monitors.show', $monitor))
            ->with('status', 'Monitor resumed.');
    }
}
