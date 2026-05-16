<?php

namespace Misakstvanu\LaravelFio;

use Illuminate\Support\ServiceProvider;
use Misakstvanu\LaravelFio\Commands\FioTestReadCommand;

class LaravelFioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fio.php', 'fio');

        $this->app->singleton('laravel-fio', function (): FioClient {
            return new FioClient(
                baseUrl: (string) config('fio.base_url', 'https://fioapi.fio.cz'),
                timeout: (int) config('fio.timeout', 30),
                connectTimeout: (int) config('fio.connect_timeout', 10),
                verifySsl: (bool) config('fio.verify_ssl', true),
            );
        });

        $this->app->singleton(FioOperations::class, function ($app): FioOperations {
            return new FioOperations($app->make(FioClient::class));
        });

        $this->app->alias('laravel-fio', FioClient::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/fio.php' => config_path('fio.php'),
        ], 'fio-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                FioTestReadCommand::class,
            ]);
        }
    }
}


