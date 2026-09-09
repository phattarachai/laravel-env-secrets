<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

$key = str_repeat('a', 32);

afterEach(function () {
    @unlink(base_path('.env.secretstest'));
    @unlink(base_path('.env.secretstest.encrypted'));
});

it('fetches the key from the box and decrypts the env for editing', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "APP_ENV=secretstest\nFOO=bar\n");

    $this->artisan('secrets:edit', ['env' => 'secretstest', '--host' => 'necta-test'])
        ->assertSuccessful();

    expect(file_get_contents(base_path('.env.secretstest')))->toBe("APP_ENV=secretstest\nFOO=bar\n");

    // The key is only ever read over ssh — never passed as a command argument.
    Process::assertRan(fn ($process): bool => ((array) $process->command)[0] === 'ssh'
        && ! collect((array) $process->command)->contains(fn (string $arg): bool => str_contains($arg, $key)));
});

it('never prints the key it fetched', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "APP_ENV=secretstest\n");

    Artisan::call('secrets:edit', ['env' => 'secretstest']);

    expect(Artisan::output())->not->toContain($key);
});

it('fails when the box key cannot be read', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->artisan('secrets:edit', ['env' => 'secretstest'])->assertFailed();
});

it('rejects an env name that is not a slug', function () {
    Process::fake();

    $this->artisan('secrets:edit', ['env' => '../evil'])->assertFailed();

    Process::assertNothingRan();
});
