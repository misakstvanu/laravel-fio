<?php

namespace Misakstvanu\LaravelFio\Tests;

use Misakstvanu\LaravelFio\Contracts\FioClientInterface;
use Misakstvanu\LaravelFio\Data\BankTransaction;
use Misakstvanu\LaravelFio\FioOperations;
use Misakstvanu\LaravelFio\Testing\FakeFioClient;

/**
 * `FioOperations::transactionsForAccount()` against recorded Fio envelopes.
 *
 * Every fixture under `tests/fixtures/period-transactions-*.json` is built on
 * the `accountStatement` body a real `GET /v1/rest/periods/<token>/…json` call
 * returned (see `.analysis/FIO-LIVE-PROBE.md` in the application repository),
 * with the account identifiers swapped for the ones the application uses. The
 * account this code fetches has no transaction inside Fio's 90-day window, so
 * the empty statement is the reproducible recording and the populated one is
 * synthesised from that envelope plus the column map `normalizeTransaction()`
 * reads.
 */
class FioOperationsTest extends TestCase
{
    private const ACCOUNT = '2801234567/2010';

    /**
     * Wires the operations wrapper onto the fake client replaying one body.
     */
    private function operationsReplaying(string $body): FioOperations
    {
        config(['fio.driver' => 'fake', 'cache.default' => 'array']);
        $this->app->forgetInstance('laravel-fio');
        $this->app->forgetInstance(FioOperations::class);

        $client = $this->app->make(FioClientInterface::class);
        $this->assertInstanceOf(FakeFioClient::class, $client);
        $client->stub('transactionsByPeriod', $body);

        return $this->app->make(FioOperations::class);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name.'.json');
    }

    public function test_an_empty_statement_yields_no_transactions_and_no_warning(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));

        $result = $operations->transactionsForAccount('token-empty', self::ACCOUNT);

        $this->assertSame([], $result->transactions);
        $this->assertNull($result->warning);
    }

    public function test_the_fetch_window_ends_today_and_reaches_sixty_days_back(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));

        $operations->transactionsForAccount('token-window', self::ACCOUNT);

        $client = $this->app->make(FioClientInterface::class);
        $this->assertInstanceOf(FakeFioClient::class, $client);

        $recorded = $client->recorded();
        $this->assertCount(1, $recorded);
        $this->assertSame('transactionsByPeriod', $recorded[0]['operation']);
        $this->assertSame(now()->subDays(60)->format('Y-m-d'), $recorded[0]['args']['from']);
        $this->assertSame(now()->format('Y-m-d'), $recorded[0]['args']['to']);
    }

    public function test_a_single_transaction_is_normalised_from_the_fio_column_map(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-single'));

        $result = $operations->transactionsForAccount('token-single', self::ACCOUNT);

        $this->assertNull($result->warning);
        $this->assertCount(1, $result->transactions);

        $transaction = $result->transactions[0];
        $this->assertInstanceOf(BankTransaction::class, $transaction);
        $this->assertSame('2026-10-04', $transaction->date);
        $this->assertSame(500.0, $transaction->amount);
        $this->assertSame('20261003003', $transaction->variableSymbol);
        $this->assertSame('2809876543/2010', $transaction->counterAccount);
        $this->assertSame('Platba za výpravu', $transaction->description);
        $this->assertSame('Rokytná Kuba', $transaction->message);
    }

    public function test_a_token_belonging_to_another_account_produces_a_warning(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-wrong-account'));

        $result = $operations->transactionsForAccount('token-wrong', self::ACCOUNT);

        $this->assertSame(
            'FIO token belongs to account 2809876543/2010, not 2801234567/2010.',
            $result->warning,
        );
        $this->assertSame([], $result->transactions);
    }

    public function test_a_lone_transaction_object_is_normalised_like_a_list(): void
    {
        $statement = json_decode($this->fixture('period-transactions-single'), true);
        $statement['accountStatement']['transactionList']['transaction']
            = $statement['accountStatement']['transactionList']['transaction'][0];

        $operations = $this->operationsReplaying((string) json_encode($statement));

        $result = $operations->transactionsForAccount('token-object', self::ACCOUNT);

        $this->assertCount(1, $result->transactions);
        $this->assertSame('2026-10-04', $result->transactions[0]->date);
        $this->assertSame('20261003003', $result->transactions[0]->variableSymbol);
    }
}
