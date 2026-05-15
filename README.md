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

