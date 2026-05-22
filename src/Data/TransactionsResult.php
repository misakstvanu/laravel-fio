<?php

namespace Misakstvanu\LaravelFio\Data;

class TransactionsResult
{
    /**
     * @param  list<BankTransaction>  $transactions
     */
    public function __construct(
        public readonly array $transactions,
        public readonly ?string $warning,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function transactionsAsArray(): array
    {
        return array_map(
            static fn (BankTransaction $transaction): array => $transaction->toArray(),
            $this->transactions,
        );
    }
}
