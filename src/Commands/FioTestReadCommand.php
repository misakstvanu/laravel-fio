<?php

namespace Misakstvanu\LaravelFio\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Misakstvanu\LaravelFio\Enums\ExportFormat;
use Misakstvanu\LaravelFio\FioClient;

class FioTestReadCommand extends Command
{
    protected $signature = 'fio:test-read
        {--token= : Fio API token. Falls back to config(fio.token)}
        {--operation=last-statement : last-statement|last-transactions|period}
        {--from= : Start date in Y-m-d for period operation}
        {--to= : End date in Y-m-d for period operation}';

    protected $description = 'Runs a simple read-only Fio API operation and prints a short result summary';

    public function handle(FioClient $client): int
    {
        $token = $this->option('token') ?: config('fio.token');

        if (! is_string($token) || trim($token) === '') {
            $this->error('Missing token. Pass --token or set fio.token in config.');

            return self::FAILURE;
        }

        $operation = (string) $this->option('operation');

        try {
            if ($operation === 'last-transactions') {
                $response = $client->lastTransactions($token, ExportFormat::Json);
                $transactions = data_get($response->json(), 'accountStatement.transactionList.transaction', []);
                $count = is_array($transactions) ? count($transactions) : 0;

                $this->info('Read OK: last-transactions');
                $this->line('Transactions returned: '.$count);

                return self::SUCCESS;
            }

            if ($operation === 'period') {
                $from = $this->option('from') ?: CarbonImmutable::now()->subDays(7)->format('Y-m-d');
                $to = $this->option('to') ?: CarbonImmutable::now()->format('Y-m-d');

                $response = $client->transactionsByPeriod($token, $from, $to, ExportFormat::Json);
                $transactions = data_get($response->json(), 'accountStatement.transactionList.transaction', []);
                $count = is_array($transactions) ? count($transactions) : 0;

                $this->info('Read OK: period');
                $this->line('Range: '.$from.' -> '.$to);
                $this->line('Transactions returned: '.$count);

                return self::SUCCESS;
            }

            $response = $client->lastStatementNumber($token);
            $xml = $response->xml();

            $this->info('Read OK: last-statement');
            $this->line('Raw response: '.trim($response->body()));

            if ($xml !== null) {
                $this->line('Root element: '.$xml->getName());
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
