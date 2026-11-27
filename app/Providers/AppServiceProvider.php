<?php

namespace App\Providers;

use App\Models\User;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\CurlHttpTransport;
use App\Support\SafeHttp\NativeDnsResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsResolverInterface::class, NativeDnsResolver::class);
        $this->app->bind(HttpTransportInterface::class, CurlHttpTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-dashboard', static fn (User $user): bool => $user->isOwner());
    }
}
