<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\BrandingSetting;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

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
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureBrandingViewData();

        // Re-run these route middleware on every Livewire update request, not only on page load:
        // a demoted admin with an open tab must not be able to keep calling component actions.
        Livewire::addPersistentMiddleware([
            EnsureEmailIsVerified::class,
            EnsureUserIsAdmin::class,
        ]);
    }

    protected function configureBrandingViewData(): void
    {
        View::composer('*', function (\Illuminate\View\View $view): void {
            $view->with('branding', once(function (): BrandingSetting {
                // Error pages must render even when the database is down: fall back to defaults.
                try {
                    return Schema::hasTable('branding_settings') ? BrandingSetting::current() : new BrandingSetting;
                } catch (\Throwable) {
                    return new BrandingSetting;
                }
            }));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
