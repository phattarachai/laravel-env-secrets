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

    /**
     * The package has no default box, so every test starts with one; tests about a missing host
     * unset it.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('env-secrets.host', 'box-test');
    }
}
