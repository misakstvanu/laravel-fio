<?php

namespace Misakstvanu\LaravelFio\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A Fio call refused because the token is still inside its cooldown window.
 *
 * Fio sends no `Retry-After` header, so the wait is carried here instead: the
 * caller can render a countdown rather than repeating the constant 30 from the
 * message.
 */
class FioRateLimitException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The refusal, carrying the whole seconds the caller has to wait.
     */
    public static function after(int $seconds, ?Throwable $previous = null): self
    {
        return new self(
            'FIO API rate limit: please wait at least 30 seconds between requests.',
            0,
            $previous,
            max(0, $seconds),
        );
    }
}
