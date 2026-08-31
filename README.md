# misakstvanu/laravel-fio

Modern Laravel client for Fio Banking API (`v1/rest`).

## Implemented operations

### Export (GET)

- `transactionsByPeriod()` -> `/periods/{token}/{from}/{to}/transactions.{format}`
- `statementsById()` -> `/by-id/{token}/{year}/{id}/transactions.{format}`
- `lastTransactions()` -> `/last/{token}/transactions.{format}`
- `setLastId()` -> `/set-last-id/{token}/{id}/`
- `setLastDate()` -> `/set-last-date/{token}/{date}/`
- `merchantTransactions()` -> `/merchant/{token}/{from}/{to}/transactions.{format}`
- `lastStatementNumber()` -> `/lastStatement/{token}/statement`

### Import (POST)

- `import()` -> `/import/` with multipart upload
- Supported types: `abo`, `xml`, `pain001_xml`, `pain008_xml`
- Optional language parameter: `cs`, `sk`, `en`

## High-level operations helper

`Misakstvanu\\LaravelFio\\FioOperations` provides app-ready helpers built on top of `FioClient`:

- `transactionsForAccount(token, configuredAccountNumber, days)`
  - fetches period transactions as JSON
  - returns `TransactionsResult` DTO with `BankTransaction` objects
  - returns mismatch warning if token account differs from configured account
- `sendPaymentOrders(token, accountNumber, repayments, importType)`
  - accepts a list of `PaymentOrder` DTO objects
  - supports `xml` and `pain001_xml`
  - applies token cooldown guard (30s)
- `cooldownRemaining(token)`
  - whole seconds left before the token may be used again, `0` when it is free
  - a refused call throws `FioRateLimitException`, whose `retryAfter` carries the same figure

## The 90-day rule

Fio serves a period export without extra ceremony only while the requested window stays inside the
last 90 days. A window reaching further back is refused with **HTTP 422** and a Czech body asking
the account owner to authorise the read in Fio internet banking; that authorisation is valid for
**10 minutes** from the moment it is granted, and the request has to be repeated inside that window.
`FioOperations` maps exactly this refusal to `FioAuthorizationRequiredException`, leaving any other
422 as a plain `RuntimeException`. `transactionsForAccount()` defaults to `days: 60`, which is
inside the limit, so the exception only appears when a caller asks for a longer history.

## Quick usage

```php
use Misakstvanu\LaravelFio\Enums\ExportFormat;

$response = app('laravel-fio')->transactionsByPeriod(
    token: 'YOUR_TOKEN',
    from: '2026-05-01',
    to: '2026-05-10',
    format: ExportFormat::Json,
);

$data = $response->json();
```

## Runner command

Package registers an artisan command for real API probing:

```bash
php artisan fio:test-read --token=YOUR_TOKEN --operation=last-statement
php artisan fio:test-read --token=YOUR_TOKEN --operation=last-transactions
php artisan fio:test-read --token=YOUR_TOKEN --operation=period --from=2026-05-01 --to=2026-05-10
```

## Testing

```bash
vendor/bin/phpunit
```

The suite is offline by default. One test, `tests/LiveSmokeTest.php`, talks to the real Fio host and
skips itself unless both variables below are set, so it never runs by accident:

```bash
FIO_LIVE_TEST=1 FIO_API_TOKEN=YOUR_TOKEN vendor/bin/phpunit --filter LiveSmokeTest
```

It issues a single `lastStatementNumber` request — the 30-second per-token cooldown makes a retry
pointless — and asserts only that the response body is non-empty. The token is never printed.
