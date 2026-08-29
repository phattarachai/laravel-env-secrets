<?php

namespace Phattarachai\EnvSecrets\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Phattarachai\EnvSecrets\EnvSecretsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [EnvSecretsServiceProvider::class];
    }
}
