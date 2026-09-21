<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        // Route outbound HTTP through the configured proxy, except for
        // domestic providers (zhipu) and local endpoints, which must connect
        // directly — the local proxy has caused stream failures and extra
        // latency for them. Array form makes this a final decision, so
        // Guzzle never falls back to HTTP(S)_PROXY environment variables.
        Http::globalOptions([
            'proxy' => [
                'http' => config('services.http_proxy'),
                'https' => config('services.http_proxy'),
                'no' => ['open.bigmodel.cn', 'localhost', '127.0.0.1'],
            ],
        ]);
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
    }
}
