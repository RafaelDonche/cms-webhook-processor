<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ContentLockManager;
use App\Infrastructure\PostgresAdvisoryLockManager;
use App\Models\Content;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ContentLockManager::class, PostgresAdvisoryLockManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('cms-webhook', fn (Request $request): Limit => Limit::perMinute(
            config()->integer('cms.webhook.rate_limit_per_minute'),
        )->by((string) $request->ip()));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(
            config()->integer('api.rate_limit_per_minute'),
        )->by('consumer:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Route::bind('content', fn (string $externalId): Content => Content::query()
            ->visibleTo($this->authenticatedConsumer())
            ->where('external_id', $externalId)
            ->firstOrFail());
    }

    private function authenticatedConsumer(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
