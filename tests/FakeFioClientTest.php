<?php

namespace Misakstvanu\LaravelFio\Tests;

use Misakstvanu\LaravelFio\Enums\ExportFormat;
use Misakstvanu\LaravelFio\Enums\ImportType;
use Misakstvanu\LaravelFio\Enums\ResponseLanguage;
use Misakstvanu\LaravelFio\Exceptions\FioTimeoutException;
use Misakstvanu\LaravelFio\FioClient;
use Misakstvanu\LaravelFio\Testing\FakeFioClient;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The offline client, exercised without a container.
 *
 * `FakeFioClient` builds its response out of a PSR-7 response by hand, so none
 * of it needs an application — which is also what lets this file run from the
 * app repository against the symlinked package.
 */
class FakeFioClientTest extends BaseTestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fio-fake-'.uniqid();
        mkdir($this->fixturePath, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->fixturePath.DIRECTORY_SEPARATOR.'*') as $file) {
            unlink((string) $file);
        }

        rmdir($this->fixturePath);

        parent::tearDown();
    }

    private function writeFixture(string $name, string $body): void
    {
        file_put_contents($this->fixturePath.DIRECTORY_SEPARATOR.$name, $body);
    }

    public function test_it_is_a_drop_in_for_the_real_client(): void
    {
        $client = new FakeFioClient($this->fixturePath);

        $this->assertInstanceOf(FioClient::class, $client);
    }

    public function test_each_operation_reads_its_own_fixture(): void
    {
        $this->writeFixture('period-transactions.json', '{"period":true}');
        $this->writeFixture('statement-by-id.json', '{"byId":true}');
        $this->writeFixture('last-transactions.json', '{"last":true}');
        $this->writeFixture('set-last-id.xml', '<setLastId/>');
        $this->writeFixture('set-last-date.xml', '<setLastDate/>');
        $this->writeFixture('merchant-transactions.xml', '<merchant/>');
        $this->writeFixture('last-statement.xml', '<response>7</response>');
        $this->writeFixture('import.xml', '<import/>');

        $client = new FakeFioClient($this->fixturePath);

        $this->assertSame('{"period":true}', $client->transactionsByPeriod('token', '2026-06-01', '2026-08-30')->body());
        $this->assertSame('{"byId":true}', $client->statementsById('token', 2026, 3)->body());
        $this->assertSame('{"last":true}', $client->lastTransactions('token')->body());
        $this->assertSame('<setLastId/>', $client->setLastId('token', 42)->body());
        $this->assertSame('<setLastDate/>', $client->setLastDate('token', '2026-06-01')->body());
        $this->assertSame('<merchant/>', $client->merchantTransactions('token', '2026-06-01', '2026-08-30')->body());
        $this->assertSame('<response>7</response>', $client->lastStatementNumber('token')->body());
        $this->assertSame('<import/>', $client->import('token', ImportType::Xml, '/tmp/orders.xml')->body());
    }

    public function test_the_response_carries_the_requested_format_and_a_200_status(): void
    {
        $this->writeFixture('period-transactions.json', '{"ok":true}');

        $response = (new FakeFioClient($this->fixturePath))->transactionsByPeriod('token', '2026-06-01', '2026-08-30');

        $this->assertSame('json', $response->format);
        $this->assertSame(200, $response->response->status());
        $this->assertSame(['ok' => true], $response->json());
    }

    public function test_the_fixture_extension_follows_the_requested_format(): void
    {
        $this->writeFixture('period-transactions.xml', '<transactions/>');

        $client = new FakeFioClient($this->fixturePath);
        $response = $client->transactionsByPeriod('token', '2026-06-01', '2026-08-30', ExportFormat::Xml);

        $this->assertSame('<transactions/>', $response->body());
        $this->assertSame('xml', $response->format);
    }

    public function test_a_format_given_as_a_string_resolves_to_the_same_fixture(): void
    {
        $this->writeFixture('last-transactions.xml', '<last/>');

        $response = (new FakeFioClient($this->fixturePath))->lastTransactions('token', 'xml');

        $this->assertSame('<last/>', $response->body());
    }

    public function test_a_stub_wins_over_the_fixture_file(): void
    {
        $this->writeFixture('period-transactions.json', '{"from":"disk"}');

        $client = new FakeFioClient($this->fixturePath);
        $returned = $client->stub('transactionsByPeriod', '{"from":"stub"}');

        $this->assertSame($client, $returned);
        $this->assertSame(
            '{"from":"stub"}',
            $client->transactionsByPeriod('token', '2026-06-01', '2026-08-30')->body(),
        );
    }

    public function test_a_stub_makes_the_fixture_file_unnecessary(): void
    {
        $client = new FakeFioClient($this->fixturePath);
        $client->stub('lastStatementNumber', '<response>1</response>');

        $this->assertSame('<response>1</response>', $client->lastStatementNumber('token')->body());
    }

    public function test_it_records_every_call_with_its_arguments(): void
    {
        $client = new FakeFioClient($this->fixturePath);
        $client->stub('lastStatementNumber', '<response>1</response>');
        $client->stub('import', '<import/>');

        $this->assertSame([], $client->recorded());

        $client->lastStatementNumber('token');
        $client->import('token', ImportType::Abo, '/tmp/orders.abo', ResponseLanguage::Czech);

        $this->assertSame(
            [
                [
                    'operation' => 'lastStatementNumber',
                    'args' => ['token' => 'token'],
                ],
                [
                    'operation' => 'import',
                    'args' => [
                        'token' => 'token',
                        'type' => ImportType::Abo,
                        'filePath' => '/tmp/orders.abo',
                        'language' => ResponseLanguage::Czech,
                    ],
                ],
            ],
            $client->recorded(),
        );
    }

    public function test_a_failed_call_is_still_recorded(): void
    {
        $client = new FakeFioClient($this->fixturePath);

        try {
            $client->setLastId('token', 9);
        } catch (RuntimeException) {
            // The missing fixture is the point of this case.
        }

        $this->assertSame([['operation' => 'setLastId', 'args' => ['token' => 'token', 'id' => 9]]], $client->recorded());
    }

    public function test_a_missing_fixture_names_the_absolute_path_it_looked_for(): void
    {
        $client = new FakeFioClient($this->fixturePath);
        $expected = realpath($this->fixturePath).DIRECTORY_SEPARATOR.'last-statement.xml';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expected);

        $client->lastStatementNumber('token');
    }

    public function test_the_fixture_path_is_reported_for_every_operation(): void
    {
        $client = new FakeFioClient($this->fixturePath);
        $root = realpath($this->fixturePath).DIRECTORY_SEPARATOR;

        $this->assertSame($root.'period-transactions.json', $client->fixtureFor('transactionsByPeriod', 'json'));
        $this->assertSame($root.'statement-by-id.json', $client->fixtureFor('statementsById', 'json'));
        $this->assertSame($root.'last-transactions.json', $client->fixtureFor('lastTransactions', 'json'));
        $this->assertSame($root.'set-last-id.xml', $client->fixtureFor('setLastId', 'xml'));
        $this->assertSame($root.'set-last-date.xml', $client->fixtureFor('setLastDate', 'xml'));
        $this->assertSame($root.'merchant-transactions.xml', $client->fixtureFor('merchantTransactions', 'xml'));
        $this->assertSame($root.'last-statement.xml', $client->fixtureFor('lastStatementNumber', 'xml'));
        $this->assertSame($root.'import.xml', $client->fixtureFor('import', 'xml'));
    }

    public function test_a_registered_error_is_raised_instead_of_the_stub(): void
    {
        $client = new FakeFioClient($this->fixturePath);
        $client->stub('transactionsByPeriod', '{"from":"stub"}');
        $returned = $client->throwOn('transactionsByPeriod', new FioTimeoutException('FIO API request timed out.'));

        $this->assertSame($client, $returned);

        try {
            $client->transactionsByPeriod('token', '2026-06-01', '2026-08-30');
            $this->fail('The registered error should have been raised.');
        } catch (FioTimeoutException $e) {
            $this->assertSame('FIO API request timed out.', $e->getMessage());
        }

        $this->assertSame(
            [[
                'operation' => 'transactionsByPeriod',
                'args' => ['token' => 'token', 'from' => '2026-06-01', 'to' => '2026-08-30', 'format' => ExportFormat::Json],
            ]],
            $client->recorded(),
        );
    }

    public function test_only_the_named_operation_fails(): void
    {
        $client = new FakeFioClient($this->fixturePath);
        $client->stub('lastStatementNumber', '<response>1</response>');
        $client->throwOn('import', new FioTimeoutException('FIO API request timed out.'));

        $this->assertSame('<response>1</response>', $client->lastStatementNumber('token')->body());
    }

    public function test_a_null_error_restores_the_stubbed_answer(): void
    {
        $client = new FakeFioClient($this->fixturePath);
        $client->stub('lastStatementNumber', '<response>1</response>');
        $client->throwOn('lastStatementNumber', new FioTimeoutException('FIO API request timed out.'));
        $client->throwOn('lastStatementNumber', null);

        $this->assertSame('<response>1</response>', $client->lastStatementNumber('token')->body());
    }

    public function test_an_unknown_operation_cannot_be_made_to_fail(): void
    {
        $client = new FakeFioClient($this->fixturePath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FIO fake: unknown operation [transactionsByYear].');

        $client->throwOn('transactionsByYear', new FioTimeoutException('FIO API request timed out.'));
    }

    public function test_an_unknown_operation_cannot_be_stubbed(): void
    {
        $client = new FakeFioClient($this->fixturePath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FIO fake: unknown operation [transactionsByYear].');

        $client->stub('transactionsByYear', '{}');
    }

    public function test_the_parents_connection_properties_are_left_uninitialised(): void
    {
        $client = new FakeFioClient($this->fixturePath);
        $parent = new ReflectionClass(FioClient::class);

        foreach (['baseUrl', 'timeout', 'connectTimeout', 'verifySsl'] as $property) {
            $this->assertFalse(
                $parent->getProperty($property)->isInitialized($client),
                sprintf('FakeFioClient must not populate the parent\'s %s.', $property),
            );
        }
    }
}
