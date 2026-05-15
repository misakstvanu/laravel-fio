<?php

return [
    'base_url' => env('FIO_API_BASE_URL', 'https://fioapi.fio.cz'),

    // Optional default token used by fio:test-read command.
    'token' => env('FIO_API_TOKEN'),

    'timeout' => (int) env('FIO_API_TIMEOUT', 30),
    'connect_timeout' => (int) env('FIO_API_CONNECT_TIMEOUT', 10),
    'verify_ssl' => filter_var(env('FIO_API_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
];

