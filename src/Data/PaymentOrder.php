<?php

namespace Misakstvanu\LaravelFio\Data;

class PaymentOrder
{
    public function __construct(
        public readonly string $name,
        public readonly string $accountNumber,
        public readonly float $amount,
        public readonly string $dueDate,
    ) {
    }
}

