<?php

namespace Phattarachai\EnvSecrets\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * One-shot provisioning for the env:encrypt secret pattern:
 *   1. mint a fresh 32-byte encryption key (CSPRNG),
 *   2. encrypt .env.<env> into the committed .env.<env>.encrypted,
 *   3. install the key on the deploy box at <dir>/<slug>.<env>.key (600, owned by the SSH user),
 *   4. drop the key on the clipboard so it can be pasted into the password manager.
 *
 * The key is never written to stdout, the shell history, or a process argument — it reaches the
 * server over ssh stdin and the clipboard via pbcopy stdin. Run this from the machine that holds
 * the plaintext .env.<env> (i.e. a developer machine), not on the server.
 *
 * Host, dir, and slug default from config/env-secrets.php; each is overridable per run with an
 * explicit --host / --dir / --slug option.
 */
class SecretsProvisionCommand extends Command
{
    protected $signature = 'secrets:provision
        {env : Environment to encrypt and provision a key for (e.g. uat, production)}
        {--host= : SSH host alias of the box that stores the decryption key (default: config env-secrets.host)}
        {--dir= : Directory on the box that holds the key files (default: config env-secrets.dir)}
        {--slug= : Filename stem — the key is written as <slug>.<env>.key (default: config, else the app name)}
        {--local : Encrypt locally only; skip installing the key on the server}';

    protected $description = 'Mint an env-encryption key, encrypt .env.<env>, and install the key on the deploy box (never prints the key).';

    public function handle(): int
    {
        $env = (string) $this->argument('env');
        $host = $this->resolveHost();
        $slug = $this->resolveSlug();
        $dir = rtrim($this->resolveDir(), '/');

        if (! $this->isSlug($env) || ! $this->isSlug($slug) || ! $this->isPath($dir)) {
            $this->error('env and slug must be [a-z0-9-]; dir must be an absolute path.');

            return self::FAILURE;
        }

        $plaintext = base_path(".env.{$env}");

        if (! file_exists($plaintext)) {
            $this->error("Nothing to encrypt: {$plaintext} does not exist.");

            return self::FAILURE;
        }

        $key = bin2hex(random_bytes(16));

        if ($this->encrypt($env, $key) !== self::SUCCESS) {
            $this->error('env:encrypt failed — key not installed.');

            return self::FAILURE;
        }

        $this->info(".env.{$env} encrypted → .env.{$env}.encrypted");

        if (! $this->option('local')) {
            $path = "{$dir}/{$slug}.{$env}.key";

            if (! $this->install($key, $host, $path)) {
                return self::FAILURE;
            }

            $this->info("Key installed at {$host}:{$path}");
        }

        $clipped = $this->toClipboard($key);

        $this->newLine();
        $this->line($clipped
            ? "→ Key copied to clipboard. Paste it into your password manager (item: {$slug} · {$env})."
            : "→ Store the key in your password manager (item: {$slug} · {$env}). Retrieve it from {$host} if needed.");
        $this->line("→ Commit .env.{$env}.encrypted.");

        return self::SUCCESS;
    }

    /**
     * Explicit --host, else config('env-secrets.host'), else a sensible fallback.
     */
    private function resolveHost(): string
    {
        return (string) ($this->option('host') ?: config('env-secrets.host') ?: 'necta');
    }

    /**
     * Explicit --dir, else config('env-secrets.dir'), else a sensible fallback.
     */
    private function resolveDir(): string
    {
        return (string) ($this->option('dir') ?: config('env-secrets.dir') ?: '/etc/secrets');
    }

    /**
     * Explicit --slug, else config('env-secrets.slug'), else a slug of the app
     * name, else the application directory name.
     */
    private function resolveSlug(): string
    {
        $slug = $this->option('slug') ?: config('env-secrets.slug');

        if (! $slug) {
            $slug = Str::slug((string) config('app.name')) ?: basename(base_path());
        }

        return (string) $slug;
    }

    /**
     * Encrypt .env.<env> with the given key via the framework's env:encrypt.
     *
     * Passing --key keeps env:encrypt from printing the generated key to stdout.
     */
    private function encrypt(string $env, string $key): int
    {
        return $this->call('env:encrypt', [
            '--env' => $env,
            '--key' => $key,
            '--force' => true,
        ]);
    }

    /**
     * Write the key to <path> on the server: create the dir (700), stream the key in over
     * ssh stdin, then lock the file down to 600 owned by the connecting user.
     */
    private function install(string $key, string $host, string $path): bool
    {
        $dir = dirname($path);

        $remote = sprintf(
            'set -e; u=$(id -un); sudo install -d -m 700 -o "$u" -g "$u" %s; sudo tee %s >/dev/null; sudo chown "$u:$u" %s; sudo chmod 600 %s',
            escapeshellarg($dir),
            escapeshellarg($path),
            escapeshellarg($path),
            escapeshellarg($path),
        );

        $result = Process::input($key)->run(['ssh', $host, $remote]);

        if (! $result->successful()) {
            $this->error("Failed to install key on {$host}: ".trim($result->errorOutput()));
        }

        return $result->successful();
    }

    /**
     * Best-effort copy to the macOS clipboard; returns whether it succeeded.
     */
    private function toClipboard(string $key): bool
    {
        try {
            return Process::input($key)->run('pbcopy')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function isSlug(string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9-]+$/', $value);
    }

    private function isPath(string $value): bool
    {
        return (bool) preg_match('#^/[A-Za-z0-9._/-]*$#', $value);
    }
}
