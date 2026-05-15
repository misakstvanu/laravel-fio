<?php

namespace Misakstvanu\LaravelFio\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Misakstvanu\LaravelFio\Data\FioResponse transactionsByPeriod(string $token, \DateTimeInterface|string $from, \DateTimeInterface|string $to, \Misakstvanu\LaravelFio\Enums\ExportFormat|string $format = 'json')
 * @method static \Misakstvanu\LaravelFio\Data\FioResponse statementsById(string $token, int $year, int $statementId, \Misakstvanu\LaravelFio\Enums\ExportFormat|string $format = 'json')
 * @method static \Misakstvanu\LaravelFio\Data\FioResponse lastTransactions(string $token, \Misakstvanu\LaravelFio\Enums\ExportFormat|string $format = 'json')
 * @method static \Misakstvanu\LaravelFio\Data\FioResponse setLastId(string $token, int $id)
 * @method static \Misakstvanu\LaravelFio\Data\FioResponse setLastDate(string $token, \DateTimeInterface|string $date)
 * @method static \Misakstvanu\LaravelFio\Data\FioResponse merchantTransactions(string $token, \DateTimeInterface|string $from, \DateTimeInterface|string $to, \Misakstvanu\LaravelFio\Enums\ExportFormat|string $format = 'xml')
 * @method static \Misakstvanu\LaravelFio\Data\FioResponse lastStatementNumber(string $token)
 * @method static \Misakstvanu\LaravelFio\Data\FioResponse import(string $token, \Misakstvanu\LaravelFio\Enums\ImportType|string $type, string $filePath, \Misakstvanu\LaravelFio\Enums\ResponseLanguage|string|null $language = null)
 */
class Fio extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-fio';
    }
}

