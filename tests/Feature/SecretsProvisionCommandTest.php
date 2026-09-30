<?php

use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
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
        $command = (array) $process->command;

        return ($command[0] ?? null) === 'ssh'
            && str_contains($command[2] ?? '', 'my-cool-app.secretstest.key');
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

it('never prints the encryption key to the console', function () {
    Process::fake();
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $exitCode = Artisan::call('secrets:provision', [
        'env' => 'secretstest',
        '--host' => 'necta-test',
        '--slug' => 'app',
    ]);

    expect($exitCode)->toBe(0);

    // The ssh stdin is the only place the key legitimately exists, so read it back from
    // there — the assertion then names the real value rather than a pattern that could drift.
    $key = null;

    Process::assertRan(function (PendingProcess $process) use (&$key): bool {
        if (((array) $process->command)[0] !== 'ssh') {
            return false;
        }

        $key = (string) $process->input;

        return true;
    });

    expect($key)->toMatch('/^[0-9a-f]{32}$/')
        ->and(Artisan::output())->not->toContain($key);
});

it('installs the key owner-only when no group is configured', function () {
    Process::fake();
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $this->artisan('secrets:provision', [
        'env' => 'secretstest',
        '--host' => 'necta-test',
        '--slug' => 'app',
    ])->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) {
        $remote = ((array) $process->command)[2] ?? '';

        return str_contains($remote, 'chmod 600')
            && str_contains($remote, '-m 700')
            && ! str_contains($remote, 'getent group');
    });
});

it('installs the key group-readable when --group is passed', function () {
    Process::fake();
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $this->artisan('secrets:provision', [
        'env' => 'secretstest',
        '--host' => 'necta-test',
        '--slug' => 'app',
        '--group' => 'rr-secrets',
    ])->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) {
        $remote = ((array) $process->command)[2] ?? '';

        // 750 on the directory matters as much as 640 on the key: without the
        // group execute bit the group cannot traverse into the directory at all.
        return str_contains($remote, "-m 750 -o \"\$u\" -g 'rr-secrets'")
            && str_contains($remote, 'chmod 640')
            && str_contains($remote, 'getent group');
    });
});

it('falls back to the configured group when --group is not passed', function () {
    Process::fake();
    config()->set('env-secrets.group', 'configured-group');
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $this->artisan('secrets:provision', [
        'env' => 'secretstest',
        '--host' => 'necta-test',
        '--slug' => 'app',
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains(((array) $process->command)[2] ?? '', "'configured-group'"));
});

it('rejects a group name that is not a valid unix group', function () {
    Process::fake();
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $this->artisan('secrets:provision', [
        'env' => 'secretstest',
        '--host' => 'necta-test',
        '--slug' => 'app',
        '--group' => 'bad group; rm -rf /',
    ])->assertFailed();

    // Nothing may be encrypted or shipped once the group is rejected.
    expect(file_exists(base_path('.env.secretstest.encrypted')))->toBeFalse();
    Process::assertNothingRan();
});

/*
 * sudo, and a failed install.
 *
 * The first block runs the generated remote script for real, with the local shells standing in
 * for the box's login shell: a Mac mini's is zsh, a Linux box's bash or dash. A `sudo` stub first
 * on PATH records any call and fails, the way sudo fails over ssh without a terminal.
 */

/**
 * Provision against a fake ssh and hand back the remote script it was given.
 *
 * @param  array<string, mixed>  $options
 */
function installScriptFor(array $options = []): string
{
    Process::fake();
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    test()->artisan('secrets:provision', ['env' => 'secretstest', '--host' => 'box', '--slug' => 'app'] + $options)
        ->assertSuccessful();

    $script = null;

    Process::assertRan(function (PendingProcess $process) use (&$script): bool {
        $command = (array) $process->command;

        return $command[0] === 'ssh' && ($script = $command[2]) !== null;
    });

    return (string) $script;
}

/**
 * Run a remote script locally in $shell, the key on stdin, with the sudo stub first on PATH.
 *
 * @return array{0: bool, 1: bool} [succeeded, sudo was called]
 */
function runInstallScript(string $shell, string $script, string $sandbox, string $key = 'k3y'): array
{
    $bin = "{$sandbox}/bin";
    @mkdir($bin, 0700, true);
    file_put_contents("{$bin}/sudo", "#!/bin/sh\ntouch {$sandbox}/sudo-called\necho 'sudo: a terminal is required to read the password' >&2\nexit 1\n");
    chmod("{$bin}/sudo", 0700);

    $result = Process::input($key)
        ->env(['PATH' => $bin.':'.getenv('PATH')])
        ->run([$shell, '-c', $script]);

    return [$result->successful(), file_exists("{$sandbox}/sudo-called")];
}

/**
 * A scratch dir under /tmp, not sys_get_temp_dir(): a macOS $TMPDIR can hold `_`, which the
 * command rightly rejects in --dir.
 */
function sandbox(): string
{
    $dir = '/tmp/env-secrets-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    return $dir;
}

function removeTree(string $dir): void
{
    Process::run(['chmod', '-R', 'u+rwx', $dir]);
    Process::run(['rm', '-rf', $dir]);
}

function mode(string $path): string
{
    clearstatcache();

    return substr(sprintf('%o', fileperms($path)), -3);
}

$shells = array_values(array_filter(['/bin/sh', '/bin/bash', '/bin/zsh'], 'is_executable'));

it('installs into a dir the ssh user owns without sudo, in :dataset', function (string $shell) {
    $sandbox = sandbox();
    $script = installScriptFor(['--dir' => "{$sandbox}/home/.config/env-secrets"]);
    Process::swap(new Factory);

    [$ok, $sudo] = runInstallScript($shell, $script, $sandbox);

    expect($ok)->toBeTrue()
        ->and($sudo)->toBeFalse()
        ->and(file_get_contents("{$sandbox}/home/.config/env-secrets/app.secretstest.key"))->toBe('k3y')
        ->and(mode("{$sandbox}/home/.config/env-secrets"))->toBe('700')
        ->and(mode("{$sandbox}/home/.config/env-secrets/app.secretstest.key"))->toBe('600')
        ->and(file_exists("{$sandbox}/home/.config/env-secrets/app.secretstest.key.tmp"))->toBeFalse();

    removeTree($sandbox);
})->with($shells);

it('installs group-readable without sudo when the ssh user is in the group, in :dataset', function (string $shell) {
    $sandbox = sandbox();
    $group = trim(Process::run(['id', '-gn'])->output());
    $script = installScriptFor(['--dir' => "{$sandbox}/keys", '--group' => $group]);
    Process::swap(new Factory);

    [$ok, $sudo] = runInstallScript($shell, $script, $sandbox);
    clearstatcache();

    expect($ok)->toBeTrue()
        ->and($sudo)->toBeFalse()
        ->and(mode("{$sandbox}/keys"))->toBe('750')
        ->and(mode("{$sandbox}/keys/app.secretstest.key"))->toBe('640')
        ->and(posix_getgrgid(filegroup("{$sandbox}/keys/app.secretstest.key"))['name'])->toBe($group);

    removeTree($sandbox);
})->with($shells);

it('replaces an existing key in place, in :dataset', function (string $shell) {
    $sandbox = sandbox();
    mkdir("{$sandbox}/keys", 0700);
    file_put_contents("{$sandbox}/keys/app.secretstest.key", 'old-key');
    $script = installScriptFor(['--dir' => "{$sandbox}/keys"]);
    Process::swap(new Factory);

    [$ok] = runInstallScript($shell, $script, $sandbox, 'new-key');

    expect($ok)->toBeTrue()
        ->and(file_get_contents("{$sandbox}/keys/app.secretstest.key"))->toBe('new-key');

    removeTree($sandbox);
})->with($shells);

it('reaches for sudo -n only when the ssh user cannot create the key dir, in :dataset', function (string $shell) {
    $sandbox = sandbox();
    mkdir("{$sandbox}/etc", 0500);
    $script = installScriptFor(['--dir' => "{$sandbox}/etc/app"]);
    Process::swap(new Factory);

    [$ok, $sudo] = runInstallScript($shell, $script, $sandbox);

    expect($ok)->toBeFalse()->and($sudo)->toBeTrue();

    removeTree($sandbox);
})->with($shells)->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can write anywhere');

it('never touches sudo with --sudo=never, even when the dir is out of reach, in :dataset', function (string $shell) {
    $sandbox = sandbox();
    mkdir("{$sandbox}/etc", 0500);
    $script = installScriptFor(['--dir' => "{$sandbox}/etc/app", '--sudo' => 'never']);
    Process::swap(new Factory);

    [$ok, $sudo] = runInstallScript($shell, $script, $sandbox);

    expect($ok)->toBeFalse()->and($sudo)->toBeFalse();

    removeTree($sandbox);
})->with($shells)->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can write anywhere');

it('leaves the old key untouched when writing the new one fails, in :dataset', function (string $shell) {
    $sandbox = sandbox();
    mkdir("{$sandbox}/keys", 0700);
    file_put_contents("{$sandbox}/keys/app.secretstest.key", 'old-key');
    // The new key is written beside the old one first; make that write fail.
    mkdir("{$sandbox}/keys/app.secretstest.key.tmp");
    $script = installScriptFor(['--dir' => "{$sandbox}/keys"]);
    Process::swap(new Factory);

    [$ok] = runInstallScript($shell, $script, $sandbox, 'new-key');

    expect($ok)->toBeFalse()
        ->and(file_get_contents("{$sandbox}/keys/app.secretstest.key"))->toBe('old-key');

    removeTree($sandbox);
})->with($shells);

it('uses sudo -n, never a bare sudo that waits on a password', function (array $options) {
    $script = installScriptFor($options);

    expect(preg_match_all('/\bsudo\b(?! -n)/', $script))->toBe(0);
})->with([
    'owner only' => [[]],
    'group' => [['--group' => 'deployers']],
    'always' => [['--sudo' => 'always']],
]);

it('resolves sudo from the option, the per-env map, then the top-level key', function (array $config, array $options, string $expect) {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    $script = installScriptFor($options);

    expect(match ($expect) {
        'auto' => str_contains($script, 'if {') && str_contains($script, 'sudo -n'),
        'always' => ! str_contains($script, 'if {') && str_contains($script, 'sudo -n install'),
        'never' => ! str_contains($script, 'sudo'),
    })->toBeTrue();
})->with([
    'default' => [[], [], 'auto'],
    'top-level never' => [['env-secrets.sudo' => 'never'], [], 'never'],
    'top-level false' => [['env-secrets.sudo' => false], [], 'never'],
    'top-level true' => [['env-secrets.sudo' => true], [], 'always'],
    'per-env false beats top-level always' => [['env-secrets.sudo' => 'always', 'env-secrets.environments' => ['secretstest' => ['sudo' => false]]], [], 'never'],
    'option beats per-env' => [['env-secrets.environments' => ['secretstest' => ['sudo' => 'never']]], ['--sudo' => 'always'], 'always'],
]);

it('rejects an unknown sudo mode before encrypting anything', function () {
    Process::fake();
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $this->artisan('secrets:provision', ['env' => 'secretstest', '--sudo' => 'maybe'])->assertFailed();

    expect(file_exists(base_path('.env.secretstest.encrypted')))->toBeFalse();
    Process::assertNothingRan();
});

it('hands the key to the clipboard with a manual install command when the install fails', function () {
    Process::fake([
        "'ssh' *" => Process::result(errorOutput: 'sudo: a terminal is required to read the password', exitCode: 1),
        'pbcopy' => Process::result(),
    ]);
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    $exitCode = Artisan::call('secrets:provision', [
        'env' => 'secretstest', '--host' => 'pc-mini', '--dir' => '/Users/deploy/.config/env-secrets', '--slug' => 'app',
    ]);

    $key = null;
    Process::assertRan(function (PendingProcess $process) use (&$key): bool {
        return $process->command === 'pbcopy' && ($key = (string) $process->input) !== '';
    });

    expect($exitCode)->toBe(1)
        // The ciphertext stays: it matches the key on the clipboard.
        ->and(file_exists(base_path('.env.secretstest.encrypted')))->toBeTrue()
        ->and(Artisan::output())
        ->toContain("pbpaste | ssh pc-mini 'umask 077; mkdir -p /Users/deploy/.config/env-secrets && chmod 700 /Users/deploy/.config/env-secrets && cat > /Users/deploy/.config/env-secrets/app.secretstest.key && chmod 600 /Users/deploy/.config/env-secrets/app.secretstest.key'")
        ->not->toContain($key);
});

it('puts the committed file back when the install fails and there is no clipboard', function (?string $previous) {
    Process::fake([
        "'ssh' *" => Process::result(errorOutput: 'Permission denied', exitCode: 1),
        'pbcopy' => Process::result(exitCode: 1),
    ]);
    file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

    if ($previous !== null) {
        file_put_contents(base_path('.env.secretstest.encrypted'), $previous);
    }

    $this->artisan('secrets:provision', ['env' => 'secretstest', '--host' => 'box', '--slug' => 'app'])->assertFailed();

    expect($previous === null
        ? file_exists(base_path('.env.secretstest.encrypted')) === false
        : file_get_contents(base_path('.env.secretstest.encrypted')) === $previous)->toBeTrue();
})->with([
    'first provision' => [null],
    'rotation' => ['eyJvbGQiOiJjaXBoZXJ0ZXh0In0='],
]);
