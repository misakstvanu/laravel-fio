<?php

namespace Misakstvanu\LaravelFio\Data;

class BankTransaction
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $date,
        public readonly float $amount,
        public readonly ?string $variableSymbol,
        public readonly ?string $counterAccount,
        public readonly ?string $description,
        public readonly ?string $message,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'amount' => $this->amount,
            'variable_symbol' => $this->variableSymbol,
            'counter_account' => $this->counterAccount,
            'description' => $this->description,
            'message' => $this->message,
        ];
    }
}

