<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

$key = str_repeat('a', 32);

beforeEach(function () {
    config()->set('env-secrets.merge.protected', ['APP_KEY', 'APP_ENV', 'DB_*']);
});

afterEach(function () {
    @unlink(base_path('.env.secretstest'));
    @unlink(base_path('.env.secretstest.encrypted'));
    @unlink(base_path('.env'));
    @unlink(base_path('.env.backup'));
});

function target(string $contents): void
{
    file_put_contents(base_path('.env'), $contents);
}

function targetContents(): string
{
    return (string) file_get_contents(base_path('.env'));
}

it('appends a missing key with its source line byte-for-byte', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "# heading\nOPENROUTER_API_KEY=\"sk-or-v1 # not-a-comment\"\n");
    target("MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect(targetContents())->toContain('OPENROUTER_API_KEY="sk-or-v1 # not-a-comment"')
        ->and(targetContents())->toStartWith("MAIL_MAILER=log\n");
});

it('leaves a key the developer already set alone, whatever its value', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "OPENROUTER_API_KEY=from-the-vault\n");
    target("OPENROUTER_API_KEY=my-own-local-override\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect(targetContents())->toBe("OPENROUTER_API_KEY=my-own-local-override\n")
        ->and(file_exists(base_path('.env.backup')))->toBeFalse();
});

it('overwrites an existing key when --replace is forced', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "OPENROUTER_API_KEY=from-the-vault\n");
    target("MAIL_MAILER=log\nOPENROUTER_API_KEY=stale\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => true, '--force' => true])
        ->assertSuccessful();

    expect(targetContents())->toBe("MAIL_MAILER=log\nOPENROUTER_API_KEY=from-the-vault\n");
});

it('replaces only the keys named by --replace=A,B', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=new-foo\nBAR=new-bar\n");
    target("FOO=old-foo\nBAR=old-bar\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => 'FOO'])
        ->assertSuccessful();

    expect(targetContents())->toBe("FOO=new-foo\nBAR=old-bar\n");
});

it('asks before overwriting and writes nothing when the answer is no', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=new-foo\n");
    target("FOO=old-foo\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => true])
        ->expectsConfirmation('Overwrite 1 existing key(s) in .env?', 'no')
        ->assertSuccessful();

    expect(targetContents())->toBe("FOO=old-foo\n");
});

it('writes nothing on --dry-run and reports add, already set and differs', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "NEW_TOKEN=abcdef123456\nSAME=identical\nDIFFERENT=from-the-vault\n");
    target("SAME=identical\nDIFFERENT=mine\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--dry-run' => true])
        ->expectsOutputToContain('add')
        ->expectsOutputToContain('(already set)')
        ->expectsOutputToContain('differs')
        ->assertSuccessful();

    expect(targetContents())->toBe("SAME=identical\nDIFFERENT=mine\n")
        ->and(file_exists(base_path('.env.backup')))->toBeFalse();
});

it('never writes a protected key, even with --replace --force', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "APP_KEY=base64:theirs\nDB_PASSWORD=theirs\nSAFE_TOKEN=flows\n");
    target("APP_KEY=base64:mine\nMAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => true, '--force' => true])
        ->assertSuccessful();

    expect(targetContents())->toContain('APP_KEY=base64:mine')
        ->and(targetContents())->not->toContain('theirs')
        ->and(targetContents())->not->toContain('DB_PASSWORD')
        ->and(targetContents())->toContain('SAFE_TOKEN=flows');
});

it('backs the target up before it changes anything', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "NEW_TOKEN=abc\n");
    target("MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect((string) file_get_contents(base_path('.env.backup')))->toBe("MAIL_MAILER=log\n");
});

it('fails without touching the target when the box key cannot be read', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    target("MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertFailed();

    expect(targetContents())->toBe("MAIL_MAILER=log\n");
});

it('fails when the target does not exist, before ever reaching for the key', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--into' => '.env.nope'])->assertFailed();

    Process::assertNothingRan();
});

it('merges into the file named by --into', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "NEW_TOKEN=abc\n");
    file_put_contents(base_path('.env.other'), "MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--into' => '.env.other'])->assertSuccessful();

    expect((string) file_get_contents(base_path('.env.other')))->toContain('NEW_TOKEN=abc');

    @unlink(base_path('.env.other'));
    @unlink(base_path('.env.other.backup'));
});

it('never prints the key it fetched, nor a full secret value', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "OPENROUTER_API_KEY=sk-or-v1-supersecret\n");
    target("MAIL_MAILER=log\n");

    Artisan::call('secrets:merge', ['env' => 'secretstest']);

    expect(Artisan::output())->not->toContain($key)
        ->and(Artisan::output())->not->toContain('sk-or-v1-supersecret');
});
