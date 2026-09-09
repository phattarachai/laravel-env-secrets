<?php

use Illuminate\Support\Facades\Process;

$key = str_repeat('a', 32);

afterEach(function () {
    @unlink(base_path('.env.secretstest'));
    @unlink(base_path('.env.secretstest.encrypted'));
});

it('reports OK when the box key decrypts the committed file', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=bar\n");

    $this->artisan('secrets:status', ['env' => 'secretstest'])
        ->assertSuccessful()
        ->expectsOutputToContain('OK');
});

it('fails when the box key does not match the committed file', function () use ($key) {
    Process::fake(['*' => Process::result(output: str_repeat('b', 32)."\n")]);
    seedEncrypted('secretstest', $key, "FOO=bar\n");

    $this->artisan('secrets:status', ['env' => 'secretstest'])->assertFailed();
});

it('fails when there is no committed encrypted file', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);

    $this->artisan('secrets:status', ['env' => 'secretstest'])->assertFailed();
});

it('reads the live deployed env with --remote', function () {
    Process::fake(['*' => Process::result(output: "APP_ENV=production\nDB_HOST=10.0.0.1\n")]);
    config()->set('env-secrets.app_path', '/var/www/app');

    $this->artisan('secrets:status', ['env' => 'production', '--remote' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('2 variables');
});

it('fails --remote when no app path is configured', function () {
    Process::fake();
    config()->set('env-secrets.app_path', null);

    $this->artisan('secrets:status', ['env' => 'production', '--remote' => true])->assertFailed();

    Process::assertNothingRan();
});
