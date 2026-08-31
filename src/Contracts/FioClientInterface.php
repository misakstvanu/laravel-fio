<?php

namespace Misakstvanu\LaravelFio\Contracts;

use DateTimeInterface;
use Misakstvanu\LaravelFio\Data\FioResponse;
use Misakstvanu\LaravelFio\Enums\ExportFormat;
use Misakstvanu\LaravelFio\Enums\ImportType;
use Misakstvanu\LaravelFio\Enums\ResponseLanguage;

/**
 * The eight Fio REST endpoints, and the only place this package touches the network.
 *
 * `FioOperations` depends on this contract rather than on `FioClient`, so a fake
 * implementation can be bound in the container without any of its logic changing.
 */
interface FioClientInterface
{
    public function transactionsByPeriod(
        string $token,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse;

    public function statementsById(
        string $token,
        int $year,
        int $statementId,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse;

    public function lastTransactions(
        string $token,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse;

    public function setLastId(string $token, int $id): FioResponse;

    public function setLastDate(string $token, DateTimeInterface|string $date): FioResponse;

    public function merchantTransactions(
        string $token,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ExportFormat|string $format = ExportFormat::Xml,
    ): FioResponse;

    public function lastStatementNumber(string $token): FioResponse;

    public function import(
        string $token,
        ImportType|string $type,
        string $filePath,
        ResponseLanguage|string|null $language = null,
    ): FioResponse;
}
