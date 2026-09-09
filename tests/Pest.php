<?php

use Illuminate\Support\Facades\Artisan;
use Phattarachai\EnvSecrets\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Seed a committed .env.<env>.encrypted for the given key, leaving no plaintext behind — the
 * starting state for the secrets:edit / reencrypt / status / show commands under test.
 */
function seedEncrypted(string $env, string $key, string $contents): void
{
    file_put_contents(base_path(".env.{$env}"), $contents);
    Artisan::call('env:encrypt', ['--env' => $env, '--key' => $key, '--force' => true]);
    @unlink(base_path(".env.{$env}"));
}
