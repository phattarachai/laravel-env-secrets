<?php

namespace Phattarachai\EnvSecrets\Commands;

use Illuminate\Support\Facades\Process;

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
 *
 * Once provisioned, edit the file later with `secrets:edit` + `secrets:reencrypt`, which reuse the
 * installed key instead of minting a new one.
 */
class SecretsProvisionCommand extends SecretsCommand
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

        if (! $this->validEnv($env)) {
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

        if (! $this->option('local') && ! $this->install($key, $this->resolveHost(), $this->keyPath($env))) {
            return self::FAILURE;
        }

        if (! $this->option('local')) {
            $this->info("Key installed at {$this->resolveHost()}:{$this->keyPath($env)}");
        }

        $clipped = $this->toClipboard($key);
        $slug = $this->resolveSlug();

        $this->newLine();
        $this->line($clipped
            ? "→ Key copied to clipboard. Paste it into your password manager (item: {$slug} · {$env})."
            : "→ Store the key in your password manager (item: {$slug} · {$env}). Retrieve it from {$this->resolveHost()} if needed.");
        $this->line("→ Commit .env.{$env}.encrypted.");

        return self::SUCCESS;
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
}
