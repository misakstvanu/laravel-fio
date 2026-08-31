<?php

namespace Misakstvanu\LaravelFio\Tests;

use InvalidArgumentException;
use Misakstvanu\LaravelFio\Contracts\FioClientInterface;
use Misakstvanu\LaravelFio\FioClient;
use Misakstvanu\LaravelFio\FioOperations;
use Misakstvanu\LaravelFio\Testing\FakeFioClient;

/**
 * Guards the `fio.driver` switch that picks the client implementation.
 *
 * Choosing between the real REST host and the offline fixture client is a
 * config change, never a code change, so this test asserts both branches of
 * the provider's binding and the shape of the config file that feeds it.
 */
class DriverBindingTest extends TestCase
{
    /**
     * Rebinds the client after a config change.
     *
     * The provider registers it as a singleton, so the instance built during
     * application boot survives a later `config()` write unless it is dropped.
     */
    private function resolveClient(string $driver, ?string $fixturePath = null): FioClientInterface
    {
        config(['fio.driver' => $driver, 'fio.fixture_path' => $fixturePath]);
        $this->app->forgetInstance('laravel-fio');

        return $this->app->make('laravel-fio');
    }

    public function test_the_config_file_ships_a_driver_and_a_fixture_path(): void
    {
        $config = require __DIR__.'/../config/fio.php';

        $this->assertSame(
            ['base_url', 'token', 'timeout', 'connect_timeout', 'verify_ssl', 'driver', 'fixture_path'],
            array_keys($config),
        );
        $this->assertSame('http', $config['driver']);
        $this->assertNull($config['fixture_path']);
    }

    public function test_the_fake_driver_resolves_the_fixture_backed_client(): void
    {
        $client = $this->resolveClient('fake');

        $this->assertInstanceOf(FakeFioClient::class, $client);
        $this->assertInstanceOf(FioClientInterface::class, $client);
    }

    public function test_the_http_driver_resolves_the_real_client(): void
    {
        $client = $this->resolveClient('http');

        $this->assertInstanceOf(FioClient::class, $client);
        $this->assertNotInstanceOf(FakeFioClient::class, $client);
    }

    public function test_both_aliases_and_the_operations_wrapper_see_the_fake(): void
    {
        $this->resolveClient('fake');
        $this->app->forgetInstance(FioOperations::class);

        $this->assertInstanceOf(FakeFioClient::class, $this->app->make(FioClient::class));
        $this->assertInstanceOf(FakeFioClient::class, $this->app->make(FioClientInterface::class));
        $this->assertSame($this->app->make('laravel-fio'), $this->app->make(FioClient::class));
        $this->assertInstanceOf(FioOperations::class, $this->app->make(FioOperations::class));
    }

    public function test_the_fake_driver_defaults_to_the_packages_own_fixture_directory(): void
    {
        $client = $this->resolveClient('fake');

        $this->assertInstanceOf(FakeFioClient::class, $client);
        $this->assertStringEndsWith(
            implode(DIRECTORY_SEPARATOR, ['tests', 'fixtures', 'period-transactions.json']),
            $client->fixtureFor('transactionsByPeriod', 'json'),
        );
    }

    public function test_a_configured_fixture_path_wins_over_the_default(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fio-driver-'.uniqid();
        mkdir($root, 0o777, true);

        $client = $this->resolveClient('fake', $root);

        $this->assertInstanceOf(FakeFioClient::class, $client);
        $this->assertSame(
            realpath($root).DIRECTORY_SEPARATOR.'last-statement.xml',
            $client->fixtureFor('lastStatementNumber', 'xml'),
        );

        rmdir($root);
    }

    public function test_an_unknown_driver_is_rejected_and_names_the_accepted_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported FIO driver [mongodb]. Accepted values: http|fake.');

        $this->resolveClient('mongodb');
    }
}
