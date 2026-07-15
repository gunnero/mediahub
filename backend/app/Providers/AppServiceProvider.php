<?php

namespace App\Providers;

use App\Services\OperationalMonitoringService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(OperationalMonitoringService $monitoring): void
    {
        RateLimiter::for('auth-login', fn (Request $request): array => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('account:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        Queue::failing(fn (JobFailed $event) => $monitoring->recordFailedJob($event));
    }
}
