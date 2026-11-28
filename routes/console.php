<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sitepulse:check-monitors')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->when(static fn (): bool => filter_var(
        config('sitepulse.scheduled_checks_enabled', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) === true);

Schedule::command('sitepulse:prune-checks')
    ->daily()
    ->withoutOverlapping(30)
    ->when(static fn (): bool => filter_var(
        config('sitepulse.prune_checks_enabled', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) === true);
