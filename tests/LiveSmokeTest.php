<?php

namespace Misakstvanu\LaravelFio\Tests;

use Misakstvanu\LaravelFio\FioClient;

/**
 * Opt-in probe answering one question: does the configured Fio token still read?
 *
 * It is the automated form of `php artisan fio:test-read --token=…`, and it is
 * skipped unless the environment arms it explicitly, so a CI run — which has
 * neither variable set — never reaches the network.
 */
class LiveSmokeTest extends TestCase
{
    /**
     * Reads the last statement number over the real REST host.
     *
     * Exactly one request is issued: `FioOperations` allows one call per token
     * per 30 seconds, so a retry here would be refused rather than retried.
     * The token variable is named through `array_keys()` instead of a second
     * literal, which keeps the file free of any mention of it outside the
     * `env()` lookup that reads it — the value is never printed or asserted on.
     */
    public function test_the_configured_token_still_reads_the_last_statement_number(): void
    {
        $environment = [
            'FIO_LIVE_TEST' => env('FIO_LIVE_TEST'),
            'FIO_API_TOKEN' => env('FIO_API_TOKEN'),
        ];

        [$enabled, $token] = array_values($environment);

        if ($enabled !== '1' || ! is_string($token) || $token === '') {
            $this->markTestSkipped(sprintf(
                'Live Fio probe is opt-in: set %s=1 and a non-empty %s to run it.',
                ...array_keys($environment),
            ));
        }

        config(['fio.driver' => 'http']);
        $this->app->forgetInstance('laravel-fio');

        $client = $this->app->make('laravel-fio');
        $this->assertInstanceOf(FioClient::class, $client);

        $body = $client->lastStatementNumber($token)->body();

        $this->assertNotSame('', trim($body), 'Fio answered the last-statement probe with an empty body.');
    }
}
