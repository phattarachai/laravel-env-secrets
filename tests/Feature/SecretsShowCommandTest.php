<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

$key = str_repeat('a', 32);

afterEach(function () {
    @unlink(base_path('.env.secretstest'));
    @unlink(base_path('.env.secretstest.encrypted'));
});

it('lists variable names with masked values and hides the secrets', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=bar\nDB_PASSWORD=supersecret\n");

    $this->artisan('secrets:show', ['env' => 'secretstest'])
        ->assertSuccessful()
        ->expectsOutputToContain('DB_PASSWORD');

    expect(Artisan::output())->not->toContain('supersecret');
});

it('reveals a single value when named', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=bar\nDB_PASSWORD=supersecret\n");

    $this->artisan('secrets:show', ['env' => 'secretstest', 'name' => 'DB_PASSWORD'])
        ->assertSuccessful()
        ->expectsOutputToContain('supersecret');
});

it('fails when the named variable is absent', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=bar\n");

    $this->artisan('secrets:show', ['env' => 'secretstest', 'name' => 'NOPE'])->assertFailed();
});

it('reveals a value from the live deployed env with --remote', function () {
    Process::fake(['*' => Process::result(output: "APP_ENV=production\nDB_HOST=10.0.0.1\n")]);
    config()->set('env-secrets.app_path', '/var/www/app');

    $this->artisan('secrets:show', ['env' => 'production', 'name' => 'DB_HOST', '--remote' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('10.0.0.1');
});
