<?php

namespace App\Livewire;

use App\Models\Monitor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MonitorOverview extends Component
{
    private const PAGE_SIZE = 15;

    #[Locked]
    public string $cursor = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', Monitor::class);
    }

    public function showPage(string $cursor = ''): void
    {
        $parameters = strlen($cursor) <= 128 ? Cursor::fromEncoded($cursor)?->toArray() : null;
        $id = $parameters['id'] ?? null;

        $this->cursor = is_int($id) && $id > 0 && is_bool($parameters['_pointsToNextItems'] ?? null)
            ? (new Cursor(['id' => $id], $parameters['_pointsToNextItems']))->encode()
            : '';
    }

    public function render(): View
    {
        try {
            $monitors = Monitor::query()
                ->forOwner(Auth::user())
                ->orderBy('id')
                ->cursorPaginate(self::PAGE_SIZE, [
                    'id', 'name', 'status', 'last_checked_at', 'last_latency_ms',
                ], cursor: $this->cursor);
        } catch (QueryException) {
            Log::warning('Unable to load the monitor overview.');
            $monitors = null;
        }

        return view('livewire.monitor-overview', ['monitors' => $monitors]);
    }
}
