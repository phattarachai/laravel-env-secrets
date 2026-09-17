<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Phattarachai\EnvSecrets\Commands\SecretsMergeCommand;

$key = str_repeat('a', 32);

beforeEach(function () {
    config()->set('env-secrets.merge.protected', ['APP_KEY', 'APP_ENV', 'DB_*']);
});

afterEach(function () {
    foreach ((array) glob(base_path('.env*')) as $path) {
        @unlink($path);
    }
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

    expect(targetContents())->toBe("MAIL_MAILER=log\n\nOPENROUTER_API_KEY=\"sk-or-v1 # not-a-comment\"\n");
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

it('carries a multi-line quoted value across as one assignment, unmangled', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    $pem = "FIREBASE_KEY=\"-----BEGIN PRIVATE KEY-----\nAAAAB3NzaC1yc2E=\nMIIEvQIBADANBg\n-----END PRIVATE KEY-----\"\nSAFE=ok\n";
    seedEncrypted('secretstest', $key, $pem);
    target("MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect(targetContents())->toBe("MAIL_MAILER=log\n\n".$pem)
        // The base64 continuation line looks like an assignment; it must not become its own key.
        ->and(targetContents())->not->toContain("\nAAAAB3NzaC1yc2E=\nAAAAB3NzaC1yc2E=");
});

it('sees the first key of a BOM-prefixed target instead of appending a shadowing duplicate', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "OPENROUTER_API_KEY=from-the-vault\n");
    target("\xEF\xBB\xBFOPENROUTER_API_KEY=mine\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect(targetContents())->toBe("\xEF\xBB\xBFOPENROUTER_API_KEY=mine\n")
        ->and(substr_count(targetContents(), 'OPENROUTER_API_KEY'))->toBe(1);
});

it('does not call a backslash escape equal to the same string without it', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, 'SMTP_PASSWORD="p@ss\\word"'."\n");
    target('SMTP_PASSWORD="p@ssword"'."\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--dry-run' => true])
        ->expectsOutputToContain('differs')
        ->assertSuccessful();
});

it('compares a quoted and a bare value as the same', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, 'FOO="bar"'."\n");
    target("FOO=bar\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--dry-run' => true])
        ->expectsOutputToContain('(already set)')
        ->assertSuccessful();
});

it('fills a key the target left empty — the cp .env.example .env case', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "OPENROUTER_API_KEY=sk-or-real\n");
    target("OPENROUTER_API_KEY=\nMAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect(targetContents())->toBe("OPENROUTER_API_KEY=sk-or-real\nMAIL_MAILER=log\n");
});

it('treats an empty --replace= as replacing nothing, even with --force', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=theirs\nBAR=theirs\n");
    target("FOO=mine\nBAR=mine\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => '', '--force' => true])
        ->assertSuccessful();

    expect(targetContents())->toBe("FOO=mine\nBAR=mine\n");
});

it('refuses to merge at all when the protected list is empty', function () use ($key) {
    config()->set('env-secrets.merge.protected', []);
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "APP_KEY=base64:theirs\n");
    target("MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertFailed();

    expect(targetContents())->toBe("MAIL_MAILER=log\n");
    Process::assertNothingRan();
});

it('gives the backup the target\'s own mode, not a world-readable one', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "NEW_TOKEN=abc\n");
    target("MAIL_MAILER=log\n");
    chmod(base_path('.env'), 0600);

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect(fileperms(base_path('.env.backup')) & 0777)->toBe(0600)
        ->and(fileperms(base_path('.env')) & 0777)->toBe(0600);
});

it('rotates an existing backup instead of eating the last good copy', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "NEW_TOKEN=abc\n");
    target("MAIL_MAILER=log\n");
    file_put_contents(base_path('.env.backup'), "PRECIOUS=hand-made\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    $rotated = (array) glob(base_path('.env.backup.*'));
    expect($rotated)->toHaveCount(1)
        ->and((string) file_get_contents($rotated[0]))->toBe("PRECIOUS=hand-made\n")
        ->and((string) file_get_contents(base_path('.env.backup')))->toBe("MAIL_MAILER=log\n");
});

it('leaves no .tmp file behind', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "NEW_TOKEN=abc\n");
    target("MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect((array) glob(base_path('.env*.tmp')))->toBe([]);
});

it('rewrites the LAST assignment when the target names a key twice', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=theirs\n");
    target("FOO=first\nMAIL_MAILER=log\nFOO=last\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => true, '--force' => true])
        ->assertSuccessful();

    expect(targetContents())->toBe("FOO=first\nMAIL_MAILER=log\nFOO=theirs\n");
});

it('refuses a --into that escapes the project root rather than silently using .env', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    target("MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--into' => '../../.ssh/config'])
        ->assertFailed();

    expect(targetContents())->toBe("MAIL_MAILER=log\n");
    Process::assertNothingRan();
});

it('masks the value it appends rather than printing it', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "OPENROUTER_API_KEY=sk-or-v1-supersecret\n");
    target("MAIL_MAILER=log\n");

    Artisan::call('secrets:merge', ['env' => 'secretstest']);

    expect(Artisan::output())->toContain('sk********')
        ->and(Artisan::output())->not->toContain('sk-or-v1-supersecret');
});

it('refuses a source whose quote is never closed, instead of swallowing the keys after it', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "SAFE_TOKEN=\"oops-unterminated\nAPP_KEY=base64:VAULTKEY\nDB_PASSWORD=prod-password\n");
    target("MAIL_MAILER=log\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertFailed();

    // The protected keys must never have reached the file, nor the backup.
    expect(targetContents())->toBe("MAIL_MAILER=log\n")
        ->and(file_exists(base_path('.env.backup')))->toBeFalse();
});

it('refuses a target whose quote is never closed, instead of appending shadowing duplicates', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "OPENROUTER_API_KEY=from-the-vault\n");
    target("FOO=\"unterminated\nOPENROUTER_API_KEY=my-own-secret\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertFailed();

    expect(targetContents())->toBe("FOO=\"unterminated\nOPENROUTER_API_KEY=my-own-secret\n");
});

it('replaces the whole multi-line assignment, leaving no stranded continuation lines', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FIREBASE_KEY=\"new-single-line\"\n");
    target("MAIL_MAILER=log\nFIREBASE_KEY=\"-----BEGIN-----\nAAAA\nBBBB\n-----END-----\"\nAFTER=1\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => true, '--force' => true])
        ->assertSuccessful();

    expect(targetContents())->toBe("MAIL_MAILER=log\nFIREBASE_KEY=\"new-single-line\"\nAFTER=1\n");
});

it('treats an explicit --replace of false as replacing nothing', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=theirs\nBAR=theirs\n");
    target("FOO=mine\nBAR=mine\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => false, '--force' => true])
        ->assertSuccessful();

    expect(targetContents())->toBe("FOO=mine\nBAR=mine\n");
});

it('resolves every escape dotenv supports, not just the ones it rejects', function () {
    $command = new ReflectionClass(SecretsMergeCommand::class);
    $valueOf = $command->getMethod('valueOf');
    $instance = $command->newInstanceWithoutConstructor();

    expect($valueOf->invoke($instance, 'A="a\\nb"'))->toBe("a\nb")
        ->and($valueOf->invoke($instance, 'A="a\\tb"'))->toBe("a\tb")
        ->and($valueOf->invoke($instance, 'A="a\\rb"'))->toBe("a\rb")
        ->and($valueOf->invoke($instance, 'A="say \\"hi\\""'))->toBe('say "hi"')
        ->and($valueOf->invoke($instance, 'A="c:\\\\x"'))->toBe('c:\\x')
        // ...and does NOT expand what dotenv leaves literal.
        ->and($valueOf->invoke($instance, 'A="\\x41"'))->toBe('\\x41');
});

it('keeps a CRLF target on CRLF when it rewrites a line', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "FOO=theirs\n");
    target("MAIL_MAILER=log\r\nFOO=mine\r\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--replace' => true, '--force' => true])
        ->assertSuccessful();

    expect(targetContents())->toBe("MAIL_MAILER=log\r\nFOO=theirs\r\n");
});

it('reads a quoted value with a trailing inline comment as the same value', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, 'FOO="bar"'."\n");
    target('FOO="bar" # set by ops'."\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest', '--dry-run' => true])
        ->expectsOutputToContain('(already set)')
        ->assertSuccessful();
});

it('fills a quoted empty placeholder that carries an inline comment', function () use ($key) {
    Process::fake(['*' => Process::result(output: $key."\n")]);
    seedEncrypted('secretstest', $key, "OPENROUTER_API_KEY=sk-or-real\n");
    target('OPENROUTER_API_KEY="" # your key here'."\n");

    $this->artisan('secrets:merge', ['env' => 'secretstest'])->assertSuccessful();

    expect(targetContents())->toBe("OPENROUTER_API_KEY=sk-or-real\n");
});

it('does not read a quoted value with trailing junk as the quoted part alone', function () {
    $command = new ReflectionClass(SecretsMergeCommand::class);
    $valueOf = $command->getMethod('valueOf');
    $instance = $command->newInstanceWithoutConstructor();

    expect($valueOf->invoke($instance, 'A="x"junk'))->not->toBe('x');
});
