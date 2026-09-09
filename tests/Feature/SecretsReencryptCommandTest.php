<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

$key = str_repeat('a', 32);

afterEach(function () {
    @unlink(base_path('.env.secretstest'));
    @unlink(base_path('.env.secretstest.encrypted'));
});

function decrypted(string $env, string $key): string
{
    return (new Encrypter($key, 'AES-256-CBC'))->decrypt((string) file_get_contents(base_path(".env.{$env}.encrypted")));
}

it('re-encrypts the edited env with the key already on the box and verifies the round-trip', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "APP_ENV=secretstest\nFOO=old\n");
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\nFOO=new\n");

    $this->artisan('secrets:reencrypt', ['env' => 'secretstest'])
        ->assertSuccessful();

    expect(decrypted('secretstest', $key))->toBe("APP_ENV=secretstest\nFOO=new\n")
        ->and(file_exists(base_path('.env.secretstest')))->toBeTrue();
});

it('deletes the plaintext when --prune is passed', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=old\n");
    file_put_contents(base_path('.env.secretstest'), "FOO=new\n");

    $this->artisan('secrets:reencrypt', ['env' => 'secretstest', '--prune' => true])
        ->assertSuccessful();

    expect(file_exists(base_path('.env.secretstest')))->toBeFalse()
        ->and(decrypted('secretstest', $key))->toBe("FOO=new\n");
});

it('never prints the key it fetched', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=old\n");
    file_put_contents(base_path('.env.secretstest'), "FOO=new\n");

    Artisan::call('secrets:reencrypt', ['env' => 'secretstest']);

    expect(Artisan::output())->not->toContain($key);
});

it('fails when there is no plaintext to encrypt', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=old\n");

    $this->artisan('secrets:reencrypt', ['env' => 'secretstest'])->assertFailed();

    // Bails before ever reaching for the key.
    Process::assertNothingRan();
});

it('fails when the box key cannot be read', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    file_put_contents(base_path('.env.secretstest'), "FOO=new\n");

    $this->artisan('secrets:reencrypt', ['env' => 'secretstest'])->assertFailed();
});
