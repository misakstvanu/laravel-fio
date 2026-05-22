<?php

namespace Misakstvanu\LaravelFio;

use Illuminate\Support\Facades\Cache;
use Misakstvanu\LaravelFio\Data\BankTransaction;
use Misakstvanu\LaravelFio\Data\PaymentOrder;
use Misakstvanu\LaravelFio\Data\TransactionsResult;
use Misakstvanu\LaravelFio\Enums\ExportFormat;
use Misakstvanu\LaravelFio\Enums\ImportType;
use Misakstvanu\LaravelFio\Exceptions\FioApiException;
use Misakstvanu\LaravelFio\Exceptions\FioRateLimitException;
use Misakstvanu\LaravelFio\Exceptions\FioTimeoutException;
use RuntimeException;

class FioOperations
{
    private const int TOKEN_COOLDOWN_SECONDS = 30;

    public function __construct(private readonly FioClient $client) {}

    public function transactionsForAccount(string $token, string $configuredAccountNumber, int $days = 60): TransactionsResult
    {
        $dateTo = now()->format('Y-m-d');
        $dateFrom = now()->subDays($days)->format('Y-m-d');

        try {
            $this->enforceTokenCooldown($token);
            $statement = $this->client
                ->transactionsByPeriod($token, $dateFrom, $dateTo, ExportFormat::Json)
                ->json();
        } catch (FioRateLimitException|FioTimeoutException $e) {
            throw $e;
        } catch (FioApiException $e) {
            throw $this->mapFioException($e);
        } catch (\Throwable $e) {
            if ($this->isTimeoutThrowable($e)) {
                throw new FioTimeoutException('FIO API request timed out.', 0, $e);
            }

            throw new RuntimeException('FIO API error: '.$e->getMessage(), 0, $e);
        }

        $accountStatement = $statement['accountStatement'] ?? [];
        $accountInfo = $accountStatement['info'] ?? [];

        $fioAccountNumber = ($accountInfo['accountId'] ?? '').'/'.($accountInfo['bankId'] ?? '');
        $warning = null;

        if ($fioAccountNumber !== '' && ! $this->accountNumberMatches($configuredAccountNumber, $fioAccountNumber)) {
            $warning = "FIO token belongs to account {$fioAccountNumber}, not {$configuredAccountNumber}.";
        }

        $rawTransactions = $accountStatement['transactionList']['transaction'] ?? [];
        if (! is_array($rawTransactions)) {
            $rawTransactions = [];
        }

        if ($rawTransactions !== [] && array_is_list($rawTransactions) === false) {
            $rawTransactions = [$rawTransactions];
        }

        $transactions = [];
        foreach ($rawTransactions as $tx) {
            if (! is_array($tx)) {
                continue;
            }

            $transactions[] = $this->normalizeTransaction($tx);
        }

        return new TransactionsResult($transactions, $warning);
    }

    /**
     * @param  list<PaymentOrder>  $repayments
     */
    public function sendPaymentOrders(
        string $token,
        string $accountNumber,
        array $repayments,
        ImportType|string $importType = ImportType::Xml,
    ): void {
        $resolvedImportType = $importType instanceof ImportType ? $importType : ImportType::fromString($importType);
        $xml = $resolvedImportType === ImportType::Pain001Xml
            ? $this->buildPain001Xml($accountNumber, $repayments)
            : $this->buildDomesticImportXml($accountNumber, $repayments);

        $tmpFile = tempnam(sys_get_temp_dir(), 'fio-import-');
        if ($tmpFile === false) {
            throw new RuntimeException('Cannot create temporary FIO import file.');
        }

        file_put_contents($tmpFile, $xml);

        try {
            $this->enforceTokenCooldown($token);
            $this->client->import(
                token: $token,
                type: $resolvedImportType,
                filePath: $tmpFile,
            );
        } catch (FioRateLimitException|FioTimeoutException $e) {
            throw $e;
        } catch (FioApiException $e) {
            throw $this->mapFioException($e);
        } catch (\Throwable $e) {
            if ($this->isTimeoutThrowable($e)) {
                throw new FioTimeoutException('FIO API request timed out.', 0, $e);
            }

            throw new RuntimeException('FIO payment order error: '.$e->getMessage(), 0, $e);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @param  array<string, mixed>  $rawTx
     */
    private function normalizeTransaction(array $rawTx): BankTransaction
    {
        return new BankTransaction(
            id: $rawTx['column22']['value'] ?? null,
            date: isset($rawTx['column0']['value']) ? substr((string) $rawTx['column0']['value'], 0, 10) : null,
            amount: (float) ($rawTx['column1']['value'] ?? 0),
            variableSymbol: $rawTx['column5']['value'] ?? null,
            counterAccount: ($rawTx['column2']['value'] ?? '').'/'.($rawTx['column3']['value'] ?? ''),
            description: $rawTx['column16']['value'] ?? null,
            message: $rawTx['column25']['value'] ?? null,
        );
    }

    /**
     * @param  list<PaymentOrder>  $repayments
     */
    private function buildDomesticImportXml(string $accountNumber, array $repayments): string
    {
        $accountFrom = explode('/', str_replace(' ', '', $accountNumber))[0];

        $items = '';
        foreach ($repayments as $repayment) {
            [$targetAccount, $bank] = explode('/', $repayment->accountNumber.'/000000');
            $items .= <<<XML
<DomesticTransaction>
    <accountFrom>{$accountFrom}</accountFrom>
    <currency>CZK</currency>
    <amount>{$repayment->amount}</amount>
    <accountTo>{$targetAccount}</accountTo>
    <bankCode>{$bank}</bankCode>
    <date>{$repayment->dueDate}</date>
    <messageForRecipient>{$this->xmlSafe($repayment->name)}</messageForRecipient>
    <paymentType>431001</paymentType>
</DomesticTransaction>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Import xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="http://www.fio.cz/schema/importIB.xsd">
    <Orders>{$items}</Orders>
</Import>
XML;
    }

    /**
     * @param  list<PaymentOrder>  $repayments
     */
    private function buildPain001Xml(string $accountNumber, array $repayments): string
    {
        $debtorIban = $this->convertCzechAccountToIban($accountNumber);
        if ($debtorIban === null) {
            throw new RuntimeException('Cannot create pain.001 import: source account must be a valid CZ account number.');
        }

        $txCount = count($repayments);
        $controlSum = number_format(array_sum(array_map(static fn (PaymentOrder $r): float => $r->amount, $repayments)), 2, '.', '');
        $messageId = 'MCCT'.now()->format('YmdHis');
        $createdAt = now()->format('Y-m-d\TH:i:s');
        $executionDate = now()->format('Y-m-d');

        $items = '';
        foreach ($repayments as $index => $repayment) {
            $creditorIban = $this->convertCzechAccountToIban($repayment->accountNumber);
            if ($creditorIban === null) {
                throw new RuntimeException('Cannot create pain.001 import: target account must be a valid CZ account number.');
            }

            $amount = number_format($repayment->amount, 2, '.', '');
            $instructionId = (string) ($index + 1);
            $endToEndId = 'REPAYMENT-'.$instructionId;

            $items .= <<<XML
<CdtTrfTxInf>
    <PmtId>
        <InstrId>{$instructionId}</InstrId>
        <EndToEndId>{$endToEndId}</EndToEndId>
    </PmtId>
    <Amt>
        <InstdAmt Ccy="EUR">{$amount}</InstdAmt>
    </Amt>
    <Cdtr>
        <Nm>{$this->xmlSafe($repayment->name)}</Nm>
    </Cdtr>
    <CdtrAcct>
        <Id>
            <IBAN>{$creditorIban}</IBAN>
        </Id>
    </CdtrAcct>
</CdtTrfTxInf>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03 pain.001.001.03.xsd">
    <CstmrCdtTrfInitn>
        <GrpHdr>
            <MsgId>{$messageId}</MsgId>
            <CreDtTm>{$createdAt}</CreDtTm>
            <NbOfTxs>{$txCount}</NbOfTxs>
            <CtrlSum>{$controlSum}</CtrlSum>
            <InitgPty>
                <Nm>Repayments</Nm>
            </InitgPty>
        </GrpHdr>
        <PmtInf>
            <PmtInfId>{$messageId}</PmtInfId>
            <PmtMtd>TRF</PmtMtd>
            <NbOfTxs>{$txCount}</NbOfTxs>
            <CtrlSum>{$controlSum}</CtrlSum>
            <ReqdExctnDt>{$executionDate}</ReqdExctnDt>
            <Dbtr>
                <Nm>Repayments</Nm>
            </Dbtr>
            <DbtrAcct>
                <Id>
                    <IBAN>{$debtorIban}</IBAN>
                </Id>
            </DbtrAcct>
            <ChrgBr>SLEV</ChrgBr>
            {$items}
        </PmtInf>
    </CstmrCdtTrfInitn>
</Document>
XML;
    }

    private function convertCzechAccountToIban(string $accountNumber): ?string
    {
        $normalized = str_replace(' ', '', $accountNumber);
        if (! str_contains($normalized, '/')) {
            return null;
        }

        [$accountPart, $bankCode] = explode('/', $normalized, 2);
        if (! ctype_digit($bankCode) || strlen($bankCode) !== 4) {
            return null;
        }

        $prefix = '0';
        $number = $accountPart;
        if (str_contains($accountPart, '-')) {
            [$prefix, $number] = explode('-', $accountPart, 2);
        }

        if (! ctype_digit($prefix) || ! ctype_digit($number)) {
            return null;
        }

        $bban = str_pad($bankCode, 4, '0', STR_PAD_LEFT)
            .str_pad($prefix, 6, '0', STR_PAD_LEFT)
            .str_pad($number, 10, '0', STR_PAD_LEFT);

        $countryNumbers = '123500';
        $remainder = $this->mod97($bban.$countryNumbers);
        $checkDigits = 98 - $remainder;

        return 'CZ'.str_pad((string) $checkDigits, 2, '0', STR_PAD_LEFT).$bban;
    }

    private function mod97(string $input): int
    {
        $checksum = 0;
        foreach (str_split($input) as $char) {
            $checksum = (int) (($checksum.$char) % 97);
        }

        return $checksum;
    }

    private function accountNumberMatches(string $configured, string $fioReturned): bool
    {
        $configuredBase = explode('/', str_replace(' ', '', $configured))[0];
        $fioBase = explode('/', str_replace(' ', '', $fioReturned))[0];

        return $configuredBase === $fioBase;
    }

    private function xmlSafe(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function enforceTokenCooldown(string $token): void
    {
        $key = 'fio.cooldown.'.sha1($token);

        if (Cache::has($key)) {
            throw new FioRateLimitException('FIO API rate limit: please wait at least 30 seconds between requests.');
        }

        Cache::put($key, true, now()->addSeconds(self::TOKEN_COOLDOWN_SECONDS));
    }

    private function mapFioException(FioApiException $exception): RuntimeException
    {
        $message = $exception->getMessage();
        $code = $exception->getCode();

        if ($code === 409 || str_contains($message, 'status 409')) {
            return new FioRateLimitException('FIO API rate limit: please wait at least 30 seconds between requests.', 0, $exception);
        }

        if (str_contains(strtolower($message), 'timed out')) {
            return new FioTimeoutException('FIO API request timed out.', 0, $exception);
        }

        return new RuntimeException($message, 0, $exception);
    }

    private function isTimeoutThrowable(\Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }
}
