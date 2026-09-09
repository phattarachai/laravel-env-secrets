<?php

namespace Phattarachai\EnvSecrets\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Shared plumbing for the secrets:* commands.
 *
 * The invariant every subclass upholds: the encryption key travels only over ssh (stdin on the way
 * out, stdout on the way back) and lives only in this process's memory. It is never printed, never
 * written to a world-readable file, and never passed as a command-line argument — so it stays out of
 * argv/ps, the shell history, and any CI log. env:encrypt / env:decrypt are driven in-process via
 * callSilently(), which keeps --key off argv and swallows the key-echoing summary line.
 */
abstract class SecretsCommand extends Command
{
    protected const CIPHER = 'AES-256-CBC';

    /**
     * Explicit --host, else config('env-secrets.host'), else a sensible fallback.
     */
    protected function resolveHost(): string
    {
        return (string) ($this->option('host') ?: config('env-secrets.host') ?: 'necta');
    }

    /**
     * Explicit --dir, else config('env-secrets.dir'), else a sensible fallback.
     */
    protected function resolveDir(): string
    {
        return (string) ($this->option('dir') ?: config('env-secrets.dir') ?: '/etc/secrets');
    }

    /**
     * Explicit --slug, else config('env-secrets.slug'), else a slug of the app
     * name, else the application directory name.
     */
    protected function resolveSlug(): string
    {
        $slug = $this->option('slug') ?: config('env-secrets.slug');

        if (! $slug) {
            $slug = Str::slug((string) config('app.name')) ?: basename(base_path());
        }

        return (string) $slug;
    }

    /**
     * Deployed application directory on the box (for --remote reads). Explicit --path,
     * else config('env-secrets.app_path'). Null disables remote reads.
     */
    protected function resolveAppPath(): ?string
    {
        $path = ($this->hasOption('path') ? $this->option('path') : null) ?: config('env-secrets.app_path');

        return $path ? rtrim((string) $path, '/') : null;
    }

    protected function keyPath(string $env): string
    {
        return sprintf('%s/%s.%s.key', rtrim($this->resolveDir(), '/'), $this->resolveSlug(), $env);
    }

    /**
     * env / slug must be [a-z0-9-]; dir must be an absolute path. Prints the reason and returns false.
     */
    protected function validEnv(string $env): bool
    {
        if ($this->isSlug($env) && $this->isSlug($this->resolveSlug()) && $this->isPath($this->resolveDir())) {
            return true;
        }

        $this->error('env and slug must be [a-z0-9-]; dir must be an absolute path.');

        return false;
    }

    /**
     * Encrypt .env.<env> with the given key via the framework's env:encrypt.
     *
     * callSilently() hands env:encrypt a NullOutput: it ends with twoColumnDetail('Key', ...), which
     * would otherwise echo the very key we passed it into the scrollback and any CI log. --key is an
     * in-process Artisan argument here, so it never reaches argv/ps either.
     */
    protected function encrypt(string $env, string $key): int
    {
        return $this->callSilently('env:encrypt', [
            '--env' => $env,
            '--key' => $key,
            '--force' => true,
        ]);
    }

    /**
     * Read the installed key back from the box over ssh. It arrives on ssh stdout into this process's
     * memory only — never printed, never an argument. Returns null (with a reason) on any failure.
     */
    protected function fetchKey(string $env): ?string
    {
        $host = $this->resolveHost();
        $path = $this->keyPath($env);

        $result = Process::run(['ssh', $host, sprintf('cat %s', escapeshellarg($path))]);

        if (! $result->successful()) {
            $this->error("Could not read the key at {$host}:{$path} — run `secrets:provision {$env}` first?");

            return null;
        }

        $key = trim($result->output());

        if ($key === '') {
            $this->error("The key file at {$host}:{$path} is empty.");

            return null;
        }

        return $key;
    }

    /**
     * Decrypt the committed .env.<env>.encrypted in memory — nothing is written to disk. Returns null
     * when the file is missing or the key does not match (invalid MAC).
     */
    protected function decryptInMemory(string $env, string $key): ?string
    {
        $file = base_path(".env.{$env}.encrypted");

        if (! file_exists($file)) {
            return null;
        }

        try {
            return (new Encrypter($this->parseKey($key), self::CIPHER))->decrypt((string) file_get_contents($file));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the live, deployed .env on the server over ssh. The deploy writes it as the web user, so
     * the reachable path is `sudo -n cat`. Returns null (with a reason) when app_path is unset or the
     * read fails.
     */
    protected function readRemoteEnv(): ?string
    {
        $appPath = $this->resolveAppPath();

        if (! $appPath) {
            $this->error('Set env-secrets.app_path (ENV_SECRETS_APP_PATH) or pass --path to read the live .env.');

            return null;
        }

        $host = $this->resolveHost();
        $file = "{$appPath}/.env";

        $result = Process::run(['ssh', $host, sprintf('sudo -n cat %s', escapeshellarg($file))]);

        if (! $result->successful()) {
            $this->error("Could not read {$host}:{$file}: ".trim($result->errorOutput()));

            return null;
        }

        return $result->output();
    }

    /**
     * @return array<string, string>
     */
    protected function parseEnv(string $contents): array
    {
        $vars = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $vars[trim($name)] = trim($value);
        }

        return $vars;
    }

    protected function parseKey(string $key): string
    {
        return Str::startsWith($key, $prefix = 'base64:')
            ? (string) base64_decode(Str::after($key, $prefix), true)
            : $key;
    }

    protected function isSlug(string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9-]+$/', $value);
    }

    protected function isPath(string $value): bool
    {
        return (bool) preg_match('#^/[A-Za-z0-9._/-]*$#', $value);
    }
}
