<?php

use App\Console\Commands\CheckMonitorsCommand;
use App\Console\Commands\CreateOwnerCommand;
use App\Console\Commands\DispatchNotificationsCommand;
use App\Console\Commands\PruneChecksCommand;
use App\Http\Middleware\ApplySecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateOwnerCommand::class,
        CheckMonitorsCommand::class,
        DispatchNotificationsCommand::class,
        PruneChecksCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ApplySecurityHeaders::class,
        ]);

        $middleware->redirectGuestsTo(static fn (): string => route('login'));
        $middleware->redirectUsersTo(static fn (): string => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
