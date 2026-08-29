<?php

namespace Phattarachai\EnvSecrets;

use Phattarachai\EnvSecrets\Commands\SecretsProvisionCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class EnvSecretsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('env-secrets')
            ->hasConfigFile()
            ->hasCommand(SecretsProvisionCommand::class);
    }
}
