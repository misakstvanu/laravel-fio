<?php

namespace Misakstvanu\LaravelFio\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

class FioApiException extends Exception
{
    public static function fromResponse(Response $response): self
    {
        $body = trim($response->body());
        $message = sprintf('Fio API request failed with status %d.', $response->status());

        if ($body !== '') {
            $message .= ' Response: '.mb_substr($body, 0, 500);
        }

        return new self($message, $response->status());
    }
}

