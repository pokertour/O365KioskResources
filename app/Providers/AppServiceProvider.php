<?php

namespace App\Providers;

use App\Models\BrandingSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
    }

    protected function configureBrandingViewData(): void
    {
        View::composer('*', function (\Illuminate\View\View $view): void {
            $view->with('branding', once(function (): BrandingSetting {
                if (! Schema::hasTable('branding_settings')) {
                    return new BrandingSetting([
                        'site_name' => null,
                        'logo_path' => null,
                    ]);
                }

                return BrandingSetting::current();
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
