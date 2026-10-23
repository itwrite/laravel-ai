<?php

namespace App\Providers;

use App\Ai\Providers\PollinationsProvider;
use App\Ai\Providers\ZhipuProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Ai;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Zhipu uses a custom AI driver so its gateway can surface the GLM
        // chain-of-thought (reasoning_content) as reasoning_* stream events,
        // which the stock openai-compatible driver would discard.
        Ai::extend('zhipu', fn ($app, array $config): ZhipuProvider => new ZhipuProvider(
            $config,
            $app->make(Dispatcher::class),
        ));

        // Pollinations uses a custom AI driver so its free text-to-image API
        // can join the "image_providers" failover chain (see config/ai.php).
        Ai::extend('pollinations', fn ($app, array $config): PollinationsProvider => new PollinationsProvider(
            $config,
            $app->make(Dispatcher::class),
        ));
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
