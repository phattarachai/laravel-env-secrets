<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

$key = str_repeat('a', 32);

beforeEach(function () {
    config()->set('env-secrets.host', 'box-a');
    config()->set('env-secrets.dir', '/etc/shared');
    config()->set('env-secrets.slug', 'app');
    config()->set('env-secrets.environments', [
        'secretstest' => ['host' => 'box-b'],
    ]);
});

afterEach(function () {
    foreach (['.env.secretstest', '.env.secretstest.encrypted', '.env.othertest', '.env.mergetarget', '.env.mergetarget.backup'] as $file) {
        @unlink(base_path($file));
    }
});

/**
 * The ssh call a command made: to which host, for which remote command. Commands resolve their box
 * once, so asserting on the first ssh run is enough.
 */
function sshRanTo(string $host, string $path): void
{
    Process::assertRan(function (PendingProcess $process) use ($host, $path) {
        $command = (array) $process->command;

        return $command[0] === 'ssh' && $command[1] === $host && str_contains($command[2], $path);
    });
}

function sshNeverRanTo(string $host): void
{
    Process::assertDidntRun(fn (PendingProcess $process) => (((array) $process->command)[1] ?? null) === $host);
}

describe('resolution order', function () {
    it('prefers the per-env host over the top-level host', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->artisan('secrets:status', ['env' => 'secretstest'])->assertFailed();

        sshRanTo('box-b', '/etc/shared/app.secretstest.key');
        sshNeverRanTo('box-a');
    });

    it('prefers an explicit --host over the per-env host', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->artisan('secrets:status', ['env' => 'secretstest', '--host' => 'box-c'])->assertFailed();

        sshRanTo('box-c', '/etc/shared/app.secretstest.key');
        sshNeverRanTo('box-b');
    });

    it('falls back to the top-level host for an env the map does not name', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->artisan('secrets:status', ['env' => 'othertest'])->assertFailed();

        sshRanTo('box-a', '/etc/shared/app.othertest.key');
    });

    it('falls back to the built-in default when neither the map nor the top level sets a host', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        config()->set('env-secrets.host', null);

        $this->artisan('secrets:status', ['env' => 'othertest'])->assertFailed();

        sshRanTo('necta', 'app.othertest.key');
    });

    it('overrides dir and slug per env, leaving the rest at the top level', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        config()->set('env-secrets.environments.secretstest', ['dir' => '/etc/staging', 'slug' => 'backoffice']);

        $this->artisan('secrets:status', ['env' => 'secretstest'])->assertFailed();

        sshRanTo('box-a', '/etc/staging/backoffice.secretstest.key');
    });

    it('lets an explicit --dir and --slug beat the per-env ones', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        config()->set('env-secrets.environments.secretstest', ['dir' => '/etc/staging', 'slug' => 'backoffice']);

        $this->artisan('secrets:status', ['env' => 'secretstest', '--dir' => '/etc/cli', '--slug' => 'cli'])->assertFailed();

        sshRanTo('box-a', '/etc/cli/cli.secretstest.key');
    });

    it('ignores a map entry that is not an array', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        config()->set('env-secrets.environments', ['secretstest' => 'box-b']);

        $this->artisan('secrets:status', ['env' => 'secretstest'])->assertFailed();

        sshRanTo('box-a', 'app.secretstest.key');
    });

    it('reads the per-env app_path for --remote', function () {
        Process::fake(['*' => Process::result(output: "APP_ENV=secretstest\n")]);
        config()->set('env-secrets.app_path', '/var/www/production');
        config()->set('env-secrets.environments.secretstest.app_path', '/var/www/staging');

        $this->artisan('secrets:status', ['env' => 'secretstest', '--remote' => true])->assertSuccessful();

        sshRanTo('box-b', '/var/www/staging/.env');
    });

    it('applies the per-env group when provisioning', function () {
        Process::fake();
        config()->set('env-secrets.group', 'everyone');
        config()->set('env-secrets.environments.secretstest.group', 'stagers');
        file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

        $this->artisan('secrets:provision', ['env' => 'secretstest'])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => ((array) $process->command)[0] === 'ssh'
            && str_contains(((array) $process->command)[2], "getent group 'stagers'"));
    });
});

describe('every command honours the per-env host', function () use ($key) {
    it('secrets:provision', function () {
        Process::fake();
        file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

        $this->artisan('secrets:provision', ['env' => 'secretstest'])->assertSuccessful();

        sshRanTo('box-b', '/etc/shared/app.secretstest.key');
        sshNeverRanTo('box-a');
    });

    it('secrets:edit', function () use ($key) {
        Process::fake(['*' => Process::result(output: $key."\n")]);
        seedEncrypted('secretstest', $key, "FOO=bar\n");

        $this->artisan('secrets:edit', ['env' => 'secretstest'])->assertSuccessful();

        sshRanTo('box-b', '/etc/shared/app.secretstest.key');
        sshNeverRanTo('box-a');
    });

    it('secrets:reencrypt', function () use ($key) {
        Process::fake(['*' => Process::result(output: $key."\n")]);
        seedEncrypted('secretstest', $key, "FOO=bar\n");
        file_put_contents(base_path('.env.secretstest'), "FOO=baz\n");

        $this->artisan('secrets:reencrypt', ['env' => 'secretstest'])->assertSuccessful();

        sshRanTo('box-b', '/etc/shared/app.secretstest.key');
        sshNeverRanTo('box-a');
    });

    it('secrets:show', function () use ($key) {
        Process::fake(['*' => Process::result(output: $key."\n")]);
        seedEncrypted('secretstest', $key, "FOO=bar\n");

        $this->artisan('secrets:show', ['env' => 'secretstest'])->assertSuccessful();

        sshRanTo('box-b', '/etc/shared/app.secretstest.key');
        sshNeverRanTo('box-a');
    });

    it('secrets:status', function () use ($key) {
        Process::fake(['*' => Process::result(output: $key."\n")]);
        seedEncrypted('secretstest', $key, "FOO=bar\n");

        $this->artisan('secrets:status', ['env' => 'secretstest'])->assertSuccessful();

        sshRanTo('box-b', '/etc/shared/app.secretstest.key');
        sshNeverRanTo('box-a');
    });

    it('secrets:merge', function () use ($key) {
        Process::fake(['*' => Process::result(output: $key."\n")]);
        config()->set('env-secrets.merge.protected', ['APP_KEY']);
        seedEncrypted('secretstest', $key, "FOO=bar\n");
        file_put_contents(base_path('.env.mergetarget'), "BAR=1\n");

        $this->artisan('secrets:merge', ['env' => 'secretstest', '--into' => '.env.mergetarget'])->assertSuccessful();

        sshRanTo('box-b', '/etc/shared/app.secretstest.key');
        sshNeverRanTo('box-a');
    });
});

describe('saying which box it resolved', function () use ($key) {
    it('prints the host and key path, with their sources, before reading the key', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->artisan('secrets:status', ['env' => 'secretstest'])
            ->expectsOutputToContain('box-b  (environments.secretstest)')
            ->expectsOutputToContain('/etc/shared/app.secretstest.key  (env-secrets.dir, env-secrets.slug)')
            ->assertFailed();
    });

    it('names an explicit --host as the source', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->artisan('secrets:status', ['env' => 'secretstest', '--host' => 'box-c'])
            ->expectsOutputToContain('box-c  (--host)')
            ->assertFailed();
    });

    it('opens every key-reading command with the box and key path', function (string $command) use ($key) {
        Process::fake(['*' => Process::result(output: $key."\n")]);
        seedEncrypted('secretstest', $key, "FOO=bar\n");
        file_put_contents(base_path('.env.secretstest'), "FOO=bar\n");

        Artisan::call($command, ['env' => 'secretstest']);

        expect(strtok(Artisan::output(), "\n"))->toContain('box-b:/etc/shared/app.secretstest.key');
    })->with(['secrets:edit', 'secrets:reencrypt', 'secrets:show', 'secrets:provision']);

    it('says nothing about a box for provision --local', function () {
        Process::fake();
        file_put_contents(base_path('.env.secretstest'), "APP_ENV=secretstest\n");

        Artisan::call('secrets:provision', ['env' => 'secretstest', '--local' => true]);

        expect(Artisan::output())->not->toContain('box-b');
    });
});
