<?php

namespace Misakstvanu\LaravelFio\Tests;

use Illuminate\Support\Facades\Http;
use Misakstvanu\LaravelFio\Enums\ExportFormat;
use Misakstvanu\LaravelFio\Enums\ImportType;
use Misakstvanu\LaravelFio\Exceptions\FioApiException;

class FioClientTest extends TestCase
{
    public function test_it_calls_last_statement_endpoint(): void
    {
        Http::fake([
            'https://fioapi.fio.cz/v1/rest/lastStatement/*/statement' => Http::response('<response>1</response>', 200),
        ]);

        $response = app('laravel-fio')->lastStatementNumber('token');

        $this->assertSame('<response>1</response>', $response->body());

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://fioapi.fio.cz/v1/rest/lastStatement/token/statement';
        });
    }

    public function test_it_calls_period_transactions_endpoint(): void
    {
        Http::fake([
            'https://fioapi.fio.cz/v1/rest/periods/*' => Http::response('{"ok":true}', 200),
        ]);

        app('laravel-fio')->transactionsByPeriod('token', '2026-05-01', '2026-05-10', ExportFormat::Json);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://fioapi.fio.cz/v1/rest/periods/token/2026-05-01/2026-05-10/transactions.json';
        });
    }

    public function test_import_requires_existing_file(): void
    {
        $this->expectException(FioApiException::class);

        app('laravel-fio')->import('token', ImportType::Xml, __DIR__.'/missing.xml');
    }
}
