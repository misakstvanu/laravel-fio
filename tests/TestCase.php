<?php

namespace Misakstvanu\LaravelFio\Tests;

use Misakstvanu\LaravelFio\LaravelFioServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelFioServiceProvider::class,
        ];
    }
}
