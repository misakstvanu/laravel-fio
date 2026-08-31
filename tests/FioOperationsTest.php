<?php

namespace Misakstvanu\LaravelFio\Tests;

use Illuminate\Support\Facades\Cache;
use Misakstvanu\LaravelFio\Contracts\FioClientInterface;
use Misakstvanu\LaravelFio\Data\BankTransaction;
use Misakstvanu\LaravelFio\Data\PaymentOrder;
use Misakstvanu\LaravelFio\Enums\ImportType;
use Misakstvanu\LaravelFio\Exceptions\FioApiException;
use Misakstvanu\LaravelFio\Exceptions\FioRateLimitException;
use Misakstvanu\LaravelFio\Exceptions\FioTimeoutException;
use Misakstvanu\LaravelFio\FioOperations;
use Misakstvanu\LaravelFio\Testing\FakeFioClient;

/**
 * `FioOperations` against recorded Fio envelopes, plus the token cooldown.
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
    private function operationsReplaying(string $body, string $operation = 'transactionsByPeriod'): FioOperations
    {
        config(['fio.driver' => 'fake', 'cache.default' => 'array']);
        $this->app->forgetInstance('laravel-fio');
        $this->app->forgetInstance(FioOperations::class);

        $this->fakeClient()->stub($operation, $body);

        return $this->app->make(FioOperations::class);
    }

    /**
     * The fake currently bound to the container.
     */
    private function fakeClient(): FakeFioClient
    {
        $client = $this->app->make(FioClientInterface::class);
        $this->assertInstanceOf(FakeFioClient::class, $client);

        return $client;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name.'.json');
    }

    private function cooldownKey(string $token): string
    {
        return 'fio.cooldown.'.sha1($token);
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

    public function test_a_second_successful_read_inside_the_window_is_refused(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));

        $operations->transactionsForAccount('token-twice', self::ACCOUNT);
        $this->assertTrue(Cache::has($this->cooldownKey('token-twice')));

        try {
            $operations->transactionsForAccount('token-twice', self::ACCOUNT);
            $this->fail('The window is still open, so the second read should have been refused.');
        } catch (FioRateLimitException $e) {
            $this->assertSame('FIO API rate limit: please wait at least 30 seconds between requests.', $e->getMessage());
            $this->assertGreaterThan(0, $e->retryAfter);
            $this->assertLessThanOrEqual(30, $e->retryAfter);
        }
    }

    public function test_an_unused_token_has_no_cooldown_left(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));

        $this->assertSame(0, $operations->cooldownRemaining('token-never-used'));
    }

    public function test_a_successful_read_leaves_at_most_a_full_window_to_wait(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));

        $operations->transactionsForAccount('token-remaining', self::ACCOUNT);

        $remaining = $operations->cooldownRemaining('token-remaining');
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(30, $remaining);
    }

    public function test_the_cooldown_counts_down_and_reaches_zero_with_the_window(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));

        $operations->transactionsForAccount('token-countdown', self::ACCOUNT);

        $this->travel(20)->seconds();
        $this->assertSame(10, $operations->cooldownRemaining('token-countdown'));

        $this->travel(10)->seconds();
        $this->assertSame(0, $operations->cooldownRemaining('token-countdown'));

        $result = $operations->transactionsForAccount('token-countdown', self::ACCOUNT);
        $this->assertSame([], $result->transactions);
    }

    public function test_a_window_opened_before_the_countdown_existed_still_refuses(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));

        /** The value a release before this countdown wrote. */
        Cache::put($this->cooldownKey('token-legacy'), true, now()->addSeconds(30));

        $this->assertSame(30, $operations->cooldownRemaining('token-legacy'));

        $this->expectException(FioRateLimitException::class);

        $operations->transactionsForAccount('token-legacy', self::ACCOUNT);
    }

    public function test_a_409_from_fio_quotes_the_thirty_second_rule(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));
        $this->fakeClient()->throwOn(
            'transactionsByPeriod',
            new FioApiException('Fio API request failed with status 409.', 409),
        );

        try {
            $operations->transactionsForAccount('token-409', self::ACCOUNT);
            $this->fail('A 409 from FIO should have been mapped to a rate-limit refusal.');
        } catch (FioRateLimitException $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function test_a_failed_read_does_not_burn_the_window(): void
    {
        $operations = $this->operationsReplaying($this->fixture('period-transactions-empty'));
        $this->fakeClient()->throwOn('transactionsByPeriod', new FioTimeoutException('FIO API request timed out.'));

        try {
            $operations->transactionsForAccount('token-retry', self::ACCOUNT);
            $this->fail('The fake was configured to fail, so the read should have thrown.');
        } catch (FioTimeoutException) {
            // The failure is the point; what matters is what it did not write.
        }

        $this->assertFalse(Cache::has($this->cooldownKey('token-retry')));

        $this->fakeClient()->throwOn('transactionsByPeriod', null);
        $result = $operations->transactionsForAccount('token-retry', self::ACCOUNT);

        $this->assertSame([], $result->transactions);
        $this->assertTrue(Cache::has($this->cooldownKey('token-retry')));
    }

    public function test_a_second_payment_order_inside_the_window_is_refused(): void
    {
        $operations = $this->operationsReplaying('<response><status>ok</status></response>', 'import');
        $order = new PaymentOrder('Rokytná Kuba', '2809876543/2010', 500.0, now()->format('Y-m-d'));

        $operations->sendPaymentOrders('token-import', self::ACCOUNT, [$order], ImportType::Xml);
        $this->assertTrue(Cache::has($this->cooldownKey('token-import')));

        $this->expectException(FioRateLimitException::class);

        $operations->sendPaymentOrders('token-import', self::ACCOUNT, [$order], ImportType::Xml);
    }

    public function test_a_failed_payment_order_does_not_burn_the_window(): void
    {
        $operations = $this->operationsReplaying('<response><status>ok</status></response>', 'import');
        $order = new PaymentOrder('Rokytná Kuba', '2809876543/2010', 500.0, now()->format('Y-m-d'));
        $this->fakeClient()->throwOn('import', new FioTimeoutException('FIO API request timed out.'));

        try {
            $operations->sendPaymentOrders('token-import-retry', self::ACCOUNT, [$order], ImportType::Xml);
            $this->fail('The fake was configured to fail, so the import should have thrown.');
        } catch (FioTimeoutException) {
            // The failure is the point; what matters is what it did not write.
        }

        $this->assertFalse(Cache::has($this->cooldownKey('token-import-retry')));

        $this->fakeClient()->throwOn('import', null);
        $operations->sendPaymentOrders('token-import-retry', self::ACCOUNT, [$order], ImportType::Xml);

        $this->assertTrue(Cache::has($this->cooldownKey('token-import-retry')));
    }
}
