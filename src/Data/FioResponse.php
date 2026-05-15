<?php

namespace Misakstvanu\LaravelFio\Data;

use Illuminate\Http\Client\Response;
use SimpleXMLElement;

class FioResponse
{
    public function __construct(
        public readonly Response $response,
        public readonly string $format,
    ) {
    }

    public function body(): string
    {
        return $this->response->body();
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $data = $this->response->json();

        return is_array($data) ? $data : [];
    }

    public function xml(): ?SimpleXMLElement
    {
        if (trim($this->body()) === '') {
            return null;
        }

        $xml = @simplexml_load_string($this->body());

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }
}

