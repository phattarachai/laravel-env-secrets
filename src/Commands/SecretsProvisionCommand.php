<?php

namespace Phattarachai\EnvSecrets\Commands;

use Illuminate\Support\Facades\Process;

/**
 * One-shot provisioning for the env:encrypt secret pattern:
 *   1. mint a fresh 32-byte encryption key (CSPRNG),
 *   2. encrypt .env.<env> into the committed .env.<env>.encrypted,
 *   3. install the key on the deploy box at <dir>/<slug>.<env>.key (600, owned by the SSH user —
 *      or 640 root:<group> when a group is configured, see --group),
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
        {--group= : Unix group granted read access to the key, so more than one teammate can run these commands (default: config env-secrets.group; none = owner-only)}
        {--local : Encrypt locally only; skip installing the key on the server}';

    protected $description = 'Mint an env-encryption key, encrypt .env.<env>, and install the key on the deploy box (never prints the key).';

    public function handle(): int
    {
        $env = (string) $this->argument('env');

        if (! $this->validEnv($env)) {
            return self::FAILURE;
        }

        $group = $this->resolveGroup();

        if ($group !== null && ! $this->isGroup($group)) {
            $this->error("Not a valid unix group name: {$group}");

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

        if (! $this->option('local') && ! $this->install($key, $this->resolveHost(), $this->keyPath($env), $group)) {
            return self::FAILURE;
        }

        if (! $this->option('local')) {
            $this->info("Key installed at {$this->resolveHost()}:{$this->keyPath($env)}"
                .($group === null ? ' (600, owner only)' : " (640, readable by group {$group})"));
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
     * Write the key to <path> on the server: create the dir, stream the key in over ssh stdin,
     * then lock the file down.
     *
     * Without a group that is 700/600 owned by the connecting user — only that one person can
     * ever run `secrets:edit` against the box. With one, the directory becomes 750 and the key
     * 640 owned by `<user>:<group>`, which is what lets a team share it: `fetchKey()` is a plain
     * `ssh <host> cat`, with no sudo to fall back on. Re-provisioning re-applies whichever of the
     * two is configured, so a key rotation cannot silently lock the rest of the team out.
     */
    private function install(string $key, string $host, string $path, ?string $group = null): bool
    {
        $dir = dirname($path);

        $remote = $group === null
            ? sprintf(
                'set -e; u=$(id -un); sudo install -d -m 700 -o "$u" -g "$u" %s; sudo tee %s >/dev/null; sudo chown "$u:$u" %s; sudo chmod 600 %s',
                escapeshellarg($dir),
                escapeshellarg($path),
                escapeshellarg($path),
                escapeshellarg($path),
            )
            : sprintf(
                'set -e; u=$(id -un); getent group %s >/dev/null || { echo "group %s does not exist on this host" >&2; exit 1; }; '
                .'sudo install -d -m 750 -o "$u" -g %s %s; sudo tee %s >/dev/null; sudo chown "$u":%s %s; sudo chmod 640 %s',
                escapeshellarg($group),
                $group,
                escapeshellarg($group),
                escapeshellarg($dir),
                escapeshellarg($path),
                escapeshellarg($group),
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
