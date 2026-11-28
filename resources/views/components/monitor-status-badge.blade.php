@props(['status'])

@php
    [$symbol, $label] = match ($status) {
        \App\Enums\MonitorStatus::UNKNOWN => ['○', 'Not checked'],
        \App\Enums\MonitorStatus::OPERATIONAL => ['✓', $status->label()],
        \App\Enums\MonitorStatus::PENDING_DOWN => ['!', 'Degraded'],
        \App\Enums\MonitorStatus::DOWN => ['×', $status->label()],
        \App\Enums\MonitorStatus::PENDING_RECOVERY => ['↗', 'Down · recovering'],
        \App\Enums\MonitorStatus::PAUSED => ['‖', $status->label()],
    };
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $status->badgeClasses() }}">
    <span aria-hidden="true">{{ $symbol }}</span>
    {{ $label }}
</span>
