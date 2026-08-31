<?php

namespace Misakstvanu\LaravelFio\Testing;

use DateTimeInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Misakstvanu\LaravelFio\Contracts\FioClientInterface;
use Misakstvanu\LaravelFio\Data\FioResponse;
use Misakstvanu\LaravelFio\Enums\ExportFormat;
use Misakstvanu\LaravelFio\Enums\ImportType;
use Misakstvanu\LaravelFio\Enums\ResponseLanguage;
use Misakstvanu\LaravelFio\FioClient;
use RuntimeException;
use Throwable;

/**
 * A fixture-backed stand-in for the real HTTP client.
 *
 * Every one of the eight endpoints is answered from an in-memory stub or from a
 * fixture file under `{$fixturePath}`, and every call is recorded, so a test can
 * run with no network and still assert on the calls that were made.
 */
class FakeFioClient extends FioClient implements FioClientInterface
{
    /**
     * The fixture basename per operation; the extension is the response format.
     *
     * @var array<string, string>
     */
    private const FIXTURES = [
        'transactionsByPeriod' => 'period-transactions',
        'statementsById' => 'statement-by-id',
        'lastTransactions' => 'last-transactions',
        'setLastId' => 'set-last-id',
        'setLastDate' => 'set-last-date',
        'merchantTransactions' => 'merchant-transactions',
        'lastStatementNumber' => 'last-statement',
        'import' => 'import',
    ];

    /**
     * In-memory response bodies, keyed by operation name.
     *
     * @var array<string, string>
     */
    private array $stubs = [];

    /**
     * Errors to raise instead of answering, keyed by operation name.
     *
     * @var array<string, Throwable>
     */
    private array $errors = [];

    /**
     * Every call in the order it was made.
     *
     * @var list<array{operation: string, args: array<string, mixed>}>
     */
    private array $recorded = [];

    /**
     * The parent constructor is deliberately not called.
     *
     * `FioClient`'s four promoted properties — `$baseUrl`, `$timeout`,
     * `$connectTimeout` and `$verifySsl` — are read only by its private
     * `http()`, which this class never reaches because every public method is
     * overridden. They are left uninitialised rather than forcing a test to
     * invent connection settings that can never be used.
     */
    public function __construct(private readonly string $fixturePath) {}

    public function transactionsByPeriod(
        string $token,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse {
        return $this->respond(
            'transactionsByPeriod',
            ['token' => $token, 'from' => $from, 'to' => $to, 'format' => $format],
            $this->formatValue($format),
        );
    }

    public function statementsById(
        string $token,
        int $year,
        int $statementId,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse {
        return $this->respond(
            'statementsById',
            ['token' => $token, 'year' => $year, 'statementId' => $statementId, 'format' => $format],
            $this->formatValue($format),
        );
    }

    public function lastTransactions(
        string $token,
        ExportFormat|string $format = ExportFormat::Json,
    ): FioResponse {
        return $this->respond(
            'lastTransactions',
            ['token' => $token, 'format' => $format],
            $this->formatValue($format),
        );
    }

    public function setLastId(string $token, int $id): FioResponse
    {
        return $this->respond('setLastId', ['token' => $token, 'id' => $id], ExportFormat::Xml->value);
    }

    public function setLastDate(string $token, DateTimeInterface|string $date): FioResponse
    {
        return $this->respond('setLastDate', ['token' => $token, 'date' => $date], ExportFormat::Xml->value);
    }

    public function merchantTransactions(
        string $token,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ExportFormat|string $format = ExportFormat::Xml,
    ): FioResponse {
        return $this->respond(
            'merchantTransactions',
            ['token' => $token, 'from' => $from, 'to' => $to, 'format' => $format],
            $this->formatValue($format),
        );
    }

    public function lastStatementNumber(string $token): FioResponse
    {
        return $this->respond('lastStatementNumber', ['token' => $token], ExportFormat::Xml->value);
    }

    public function import(
        string $token,
        ImportType|string $type,
        string $filePath,
        ResponseLanguage|string|null $language = null,
    ): FioResponse {
        return $this->respond(
            'import',
            ['token' => $token, 'type' => $type, 'filePath' => $filePath, 'language' => $language],
            ExportFormat::Xml->value,
        );
    }

    /**
     * Registers an in-memory body that wins over any fixture file.
     */
    public function stub(string $operation, string $body): static
    {
        $this->assertKnownOperation($operation);

        $this->stubs[$operation] = $body;

        return $this;
    }

    /**
     * Makes the operation fail, the way a timeout or a 500 from FIO would.
     *
     * The call is still recorded before the error is raised, so a test can tell
     * a refused call apart from one that never left. Passing `null` clears a
     * previously registered error and restores the stub or fixture answer.
     */
    public function throwOn(string $operation, ?Throwable $error): static
    {
        $this->assertKnownOperation($operation);

        if ($error === null) {
            unset($this->errors[$operation]);

            return $this;
        }

        $this->errors[$operation] = $error;

        return $this;
    }

    /**
     * @return list<array{operation: string, args: array<string, mixed>}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * The absolute path a fixture for this operation and format would live at.
     *
     * The path is reported verbatim in the missing-fixture exception, so a
     * developer is told which file to add.
     */
    public function fixtureFor(string $operation, string $format): string
    {
        $this->assertKnownOperation($operation);

        $root = realpath($this->fixturePath) ?: $this->fixturePath;

        return $root.DIRECTORY_SEPARATOR.self::FIXTURES[$operation].'.'.$format;
    }

    /**
     * Records the call and wraps the resolved body in a real client response.
     *
     * @param  array<string, mixed>  $args
     *
     * @throws Throwable the error registered for this operation, if any
     */
    private function respond(string $operation, array $args, string $format): FioResponse
    {
        $this->recorded[] = ['operation' => $operation, 'args' => $args];

        if (array_key_exists($operation, $this->errors)) {
            throw $this->errors[$operation];
        }

        return new FioResponse(
            new Response(new Psr7Response(200, [], $this->bodyFor($operation, $format))),
            $format,
        );
    }

    /**
     * Resolves a stub first, then a fixture file on disk.
     *
     * @throws RuntimeException when neither exists
     */
    private function bodyFor(string $operation, string $format): string
    {
        if (array_key_exists($operation, $this->stubs)) {
            return $this->stubs[$operation];
        }

        $fixture = $this->fixtureFor($operation, $format);

        if (! is_file($fixture)) {
            throw new RuntimeException(sprintf('FIO fake: missing fixture %s', $fixture));
        }

        return (string) file_get_contents($fixture);
    }

    private function formatValue(ExportFormat|string $format): string
    {
        return ($format instanceof ExportFormat ? $format : ExportFormat::fromString($format))->value;
    }

    /**
     * @throws RuntimeException when the operation is not one of the eight endpoints
     */
    private function assertKnownOperation(string $operation): void
    {
        if (! array_key_exists($operation, self::FIXTURES)) {
            throw new RuntimeException(sprintf(
                'FIO fake: unknown operation [%s]. Known operations: %s.',
                $operation,
                implode(', ', array_keys(self::FIXTURES)),
            ));
        }
    }
}
