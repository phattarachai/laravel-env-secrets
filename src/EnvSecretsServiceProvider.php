<?php

namespace Phattarachai\EnvSecrets;

use Phattarachai\EnvSecrets\Commands\SecretsEditCommand;
use Phattarachai\EnvSecrets\Commands\SecretsProvisionCommand;
use Phattarachai\EnvSecrets\Commands\SecretsReencryptCommand;
use Phattarachai\EnvSecrets\Commands\SecretsShowCommand;
use Phattarachai\EnvSecrets\Commands\SecretsStatusCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class EnvSecretsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('env-secrets')
            ->hasConfigFile()
            ->hasCommands([
                SecretsProvisionCommand::class,
                SecretsEditCommand::class,
                SecretsReencryptCommand::class,
                SecretsStatusCommand::class,
                SecretsShowCommand::class,
            ]);
    }
}
