<?php

namespace Misakstvanu\LaravelFio;

use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Misakstvanu\LaravelFio\Data\FioResponse;
use Misakstvanu\LaravelFio\Enums\ExportFormat;
use Misakstvanu\LaravelFio\Enums\ImportType;
use Misakstvanu\LaravelFio\Enums\ResponseLanguage;
use Misakstvanu\LaravelFio\Exceptions\FioApiException;

class FioClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly bool $verifySsl,
    ) {
    }

    public function transactionsByPeriod(
        string $token,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse {
        $formatValue = $this->resolveFormat($format)->value;

        return $this->get(
            sprintf(
                '/v1/rest/periods/%s/%s/%s/transactions.%s',
                $token,
                $this->normalizeDate($from),
                $this->normalizeDate($to),
                $formatValue,
            ),
            $formatValue,
        );
    }

    public function statementsById(
        string $token,
        int $year,
        int $statementId,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse {
        $formatValue = $this->resolveFormat($format)->value;

        return $this->get(
            sprintf('/v1/rest/by-id/%s/%d/%d/transactions.%s', $token, $year, $statementId, $formatValue),
            $formatValue,
        );
    }

    public function lastTransactions(
        string $token,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse {
        $formatValue = $this->resolveFormat($format)->value;

        return $this->get(
            sprintf('/v1/rest/last/%s/transactions.%s', $token, $formatValue),
            $formatValue,
        );
    }

    public function setLastId(string $token, int $id): FioResponse
    {
        return $this->get(
            sprintf('/v1/rest/set-last-id/%s/%d/', $token, $id),
            ExportFormat::Xml->value,
        );
    }

    public function setLastDate(string $token, DateTimeInterface|string $date): FioResponse
    {
        return $this->get(
            sprintf('/v1/rest/set-last-date/%s/%s/', $token, $this->normalizeDate($date)),
            ExportFormat::Xml->value,
        );
    }

    public function merchantTransactions(
        string $token,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ExportFormat|string $format = ExportFormat::Xml,
    ): FioResponse {
        $formatValue = $this->resolveFormat($format)->value;

        return $this->get(
            sprintf(
                '/v1/rest/merchant/%s/%s/%s/transactions.%s',
                $token,
                $this->normalizeDate($from),
                $this->normalizeDate($to),
                $formatValue,
            ),
            $formatValue,
        );
    }

    public function lastStatementNumber(string $token): FioResponse
    {
        return $this->get(sprintf('/v1/rest/lastStatement/%s/statement', $token), ExportFormat::Xml->value);
    }

    public function import(
        string $token,
        ImportType|string $type,
        string $filePath,
        ResponseLanguage|string|null $language = null,
    ): FioResponse {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new FioApiException(sprintf('Import file "%s" does not exist or is not readable.', $filePath));
        }

        $request = $this->http()->attach('file', file_get_contents($filePath), basename($filePath));

        $payload = [
            'token' => $token,
            'type' => $this->resolveImportType($type)->value,
        ];

        if ($language !== null) {
            $payload['lng'] = $this->resolveLanguage($language)->value;
        }

        $response = $request->post('/v1/rest/import/', $payload);

        return $this->toFioResponse($response, ExportFormat::Xml->value);
    }

    private function get(string $uri, string $format): FioResponse
    {
        return $this->toFioResponse($this->http()->get($uri), $format);
    }

    private function toFioResponse(Response $response, string $format): FioResponse
    {
        if (! $response->successful()) {
            throw FioApiException::fromResponse($response);
        }

        return new FioResponse($response, $format);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions([
                'verify' => $this->verifySsl,
            ]);
    }

    private function normalizeDate(DateTimeInterface|string $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return Carbon::parse($date)->format('Y-m-d');
    }

    private function resolveFormat(ExportFormat|string $format): ExportFormat
    {
        return $format instanceof ExportFormat ? $format : ExportFormat::fromString($format);
    }

    private function resolveImportType(ImportType|string $type): ImportType
    {
        return $type instanceof ImportType ? $type : ImportType::fromString($type);
    }

    private function resolveLanguage(ResponseLanguage|string $language): ResponseLanguage
    {
        return $language instanceof ResponseLanguage ? $language : ResponseLanguage::from(strtolower($language));
    }
}

