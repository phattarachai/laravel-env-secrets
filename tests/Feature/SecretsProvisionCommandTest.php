<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

afterEach(function () {
    @unlink(base_path('.env.secretstest'));
    @unlink(base_path('.env.secretstest.encrypted'));
});

it('encrypts the env file and installs the key over ssh without exposing it', function () {
    Process::fake();
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\nFOO=bar\n");

    $this->artisan('secrets:provision', [
        'env' => 'secretstest',
        '--host' => 'necta-test',
        '--slug' => 'app',
    ])->assertSuccessful();

    expect(file_exists(base_path('.env.secretstest.encrypted')))->toBeTrue();

    // The key only ever travels over ssh stdin — never as a command argument.
    Process::assertRan(function (PendingProcess $process) {
        $command = (array) $process->command;

        return $command[0] === 'ssh'
            && in_array('necta-test', $command, true)
            && str_contains($command[2], 'app.secretstest.key')
            && preg_match('/^[0-9a-f]{32}$/', (string) $process->input) === 1
            && ! collect($command)->contains(fn (string $arg): bool => (bool) preg_match('/[0-9a-f]{32}/', $arg));
    });
});

it('falls back to the configured host, dir and slug when no options are passed', function () {
    Process::fake();
    config()->set('env-secrets.host', 'configured-host');
    config()->set('env-secrets.dir', '/etc/example');
    config()->set('env-secrets.slug', 'configured-slug');
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $this->artisan('secrets:provision', ['env' => 'secretstest'])->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) {
        $command = (array) $process->command;

        return $command[0] === 'ssh'
            && in_array('configured-host', $command, true)
            && str_contains($command[2], '/etc/example/configured-slug.secretstest.key');
    });
});

it('derives the slug from the app name when config slug is null', function () {
    Process::fake();
    config()->set('env-secrets.slug', null);
    config()->set('app.name', 'My Cool App');
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $this->artisan('secrets:provision', ['env' => 'secretstest'])->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) {
        return str_contains(((array) $process->command)[2], 'my-cool-app.secretstest.key');
    });
});

it('encrypts locally only when --local is passed', function () {
    Process::fake();
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $this->artisan('secrets:provision', ['env' => 'secretstest', '--local' => true])
        ->assertSuccessful();

    expect(file_exists(base_path('.env.secretstest.encrypted')))->toBeTrue();

    Process::assertNotRan(fn (PendingProcess $process): bool => ((array) $process->command)[0] === 'ssh');
});

it('fails when the plaintext env file is missing', function () {
    Process::fake();

    $this->artisan('secrets:provision', ['env' => 'secretstest'])
        ->assertFailed();

    Process::assertNothingRan();
});

it('rejects an env name that is not a slug', function () {
    Process::fake();

    $this->artisan('secrets:provision', ['env' => '../evil'])
        ->assertFailed();

    Process::assertNothingRan();
});
