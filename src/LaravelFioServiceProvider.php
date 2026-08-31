<?php

namespace Misakstvanu\LaravelFio;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Misakstvanu\LaravelFio\Commands\FioTestReadCommand;
use Misakstvanu\LaravelFio\Contracts\FioClientInterface;
use Misakstvanu\LaravelFio\Testing\FakeFioClient;

class LaravelFioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fio.php', 'fio');

        $this->app->singleton('laravel-fio', function (): FioClientInterface {
            $driver = config('fio.driver', 'http');

            return match ($driver) {
                'http' => new FioClient(
                    baseUrl: (string) config('fio.base_url', 'https://fioapi.fio.cz'),
                    timeout: (int) config('fio.timeout', 30),
                    connectTimeout: (int) config('fio.connect_timeout', 10),
                    verifySsl: (bool) config('fio.verify_ssl', true),
                ),
                'fake' => new FakeFioClient(config('fio.fixture_path') ?: __DIR__.'/../tests/fixtures'),
                default => throw new InvalidArgumentException(sprintf(
                    'Unsupported FIO driver [%s]. Accepted values: http|fake.',
                    is_scalar($driver) ? (string) $driver : get_debug_type($driver),
                )),
            };
        });

        $this->app->singleton(FioOperations::class, function ($app): FioOperations {
            return new FioOperations($app->make(FioClient::class));
        });

        $this->app->alias('laravel-fio', FioClient::class);
        $this->app->alias('laravel-fio', FioClientInterface::class);
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
