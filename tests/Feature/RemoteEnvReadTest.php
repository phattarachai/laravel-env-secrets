<?php

use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;

/*
 * `secrets:status --remote` / `secrets:show --remote` read the live .env with the `sudo` setting:
 * a plain cat first under auto, sudo -n only on a permission error, and never / always pinning it.
 */

beforeEach(function () {
    config()->set('env-secrets.app_path', '/var/www/app');
});

/**
 * Assert the box saw exactly these remote commands, in this order, and nothing else.
 */
function assertReadWith(string ...$commands): void
{
    Process::assertRanInOrder(array_map(fn (string $command) => ['ssh', 'box-test', $command], $commands));
}

function denied(): FakeProcessResult
{
    return Process::result(errorOutput: 'cat: /var/www/app/.env: Permission denied', exitCode: 1);
}

it('reads with a plain cat and no sudo when the ssh user can read the file', function () {
    Process::fake(['*' => Process::result(output: "APP_ENV=production\n")]);

    $this->artisan('secrets:status', ['env' => 'production', '--remote' => true])->assertSuccessful();

    assertReadWith("LC_ALL=C cat '/var/www/app/.env'");
});

it('falls back to sudo -n cat only when the plain cat is denied', function () {
    Process::fake(['*' => Process::sequence()
        ->push(denied())
        ->push(Process::result(output: "APP_ENV=production\nDB_HOST=10.0.0.1\n"))]);

    $this->artisan('secrets:status', ['env' => 'production', '--remote' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('2 variables');

    assertReadWith("LC_ALL=C cat '/var/www/app/.env'", "sudo -n cat '/var/www/app/.env'");
});

it('does not reach for sudo when the plain cat fails for another reason', function () {
    Process::fake(['*' => Process::result(errorOutput: 'cat: /var/www/app/.env: No such file or directory', exitCode: 1)]);

    $this->artisan('secrets:status', ['env' => 'production', '--remote' => true])
        ->assertFailed()
        ->expectsOutputToContain('No such file or directory');

    assertReadWith("LC_ALL=C cat '/var/www/app/.env'");
});

it('says what to fix when the fallback sudo wants a password', function () {
    Process::fake(['*' => Process::sequence()
        ->push(denied())
        ->push(Process::result(errorOutput: 'sudo: a password is required', exitCode: 1))]);

    $this->artisan('secrets:show', ['env' => 'production', '--remote' => true])
        ->assertFailed()
        ->expectsOutputToContain('sudo wants a password');
});

it('never runs sudo with sudo never, even when the plain cat is denied', function () {
    Process::fake(['*' => denied()]);
    config()->set('env-secrets.environments.production.sudo', 'never');

    $this->artisan('secrets:show', ['env' => 'production', '--remote' => true])
        ->assertFailed()
        ->expectsOutputToContain('Permission denied');

    assertReadWith("LC_ALL=C cat '/var/www/app/.env'");
});

it('goes straight to sudo -n cat with sudo always', function (array $config, array $options) {
    Process::fake(['*' => Process::result(output: "DB_HOST=10.0.0.1\n")]);

    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    $this->artisan('secrets:show', ['env' => 'production', 'name' => 'DB_HOST', '--remote' => true] + $options)
        ->assertSuccessful()
        ->expectsOutputToContain('10.0.0.1');

    assertReadWith("sudo -n cat '/var/www/app/.env'");
})->with([
    'option' => [[], ['--sudo' => 'always']],
    'top-level true' => [['env-secrets.sudo' => true], []],
    'option beats per-env never' => [['env-secrets.environments' => ['production' => ['sudo' => 'never']]], ['--sudo' => 'always']],
]);

it('rejects an unknown sudo mode before reaching the box', function () {
    Process::fake();

    $this->artisan('secrets:status', ['env' => 'production', '--remote' => true, '--sudo' => 'maybe'])->assertFailed();

    Process::assertNothingRan();
});
