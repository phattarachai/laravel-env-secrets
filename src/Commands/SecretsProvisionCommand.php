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
 * sudo is used only where it is needed (see installScript()), so a key dir inside the ssh user's
 * home works on a box with no passwordless sudo. If the install fails anyway, the new key is not
 * lost: it goes to the clipboard with the command to install it by hand (see salvage()).
 *
 * The key is never written to stdout, the shell history, or a process argument — it reaches the
 * server over ssh stdin and the clipboard via pbcopy stdin. Run this from the machine that holds
 * the plaintext .env.<env> (i.e. a developer machine), not on the server.
 *
 * Host, dir, slug and group default from config/env-secrets.php — per env when the
 * `environments` map names this one — and each is overridable per run with an explicit option.
 *
 * Once provisioned, edit the file later with `secrets:edit` + `secrets:reencrypt`, which reuse the
 * installed key instead of minting a new one.
 */
class SecretsProvisionCommand extends SecretsCommand
{
    protected $signature = 'secrets:provision
        {env : Environment to encrypt and provision a key for (e.g. uat, production)}
        {--host= : SSH host alias of the box that stores the decryption key (default: config env-secrets.environments.<env>.host, else env-secrets.host)}
        {--dir= : Directory on the box that holds the key files (default: config env-secrets.environments.<env>.dir, else env-secrets.dir)}
        {--slug= : Filename stem — the key is written as <slug>.<env>.key (default: config env-secrets.environments.<env>.slug, else env-secrets.slug, else the app name)}
        {--group= : Unix group granted read access to the key, so more than one teammate can run these commands (default: config env-secrets.environments.<env>.group, else env-secrets.group; none = owner-only)}
        {--sudo= : How the install may use sudo: auto (only when the ssh user cannot own the key dir), always, or never (default: config env-secrets.environments.<env>.sudo, else env-secrets.sudo, else auto)}
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

        $sudo = $this->resolveSudo();

        if ($sudo === null) {
            $this->error('sudo must be auto, always or never (or true / false in config).');

            return self::FAILURE;
        }

        if (! $this->option('local')) {
            $this->announceTarget($env);
        }

        $plaintext = base_path(".env.{$env}");

        if (! file_exists($plaintext)) {
            $this->error("Nothing to encrypt: {$plaintext} does not exist.");

            return self::FAILURE;
        }

        $previous = file_exists($this->encryptedPath($env)) ? (string) file_get_contents($this->encryptedPath($env)) : null;
        $key = bin2hex(random_bytes(16));

        if ($this->encrypt($env, $key) !== self::SUCCESS) {
            $this->error('env:encrypt failed — key not installed.');

            return self::FAILURE;
        }

        $this->info(".env.{$env} encrypted → .env.{$env}.encrypted");

        if (! $this->option('local') && ! $this->install($key, $this->resolveHost(), $this->keyPath($env), $group, $sudo)) {
            return $this->salvage($env, $key, $group, $previous);
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
     * How the install step may use sudo — see setting() for the order; the config value may also be
     * a plain boolean (true = always, false = never), which is why it is not read through setting():
     * that treats `false` as "unset" and would fall through to the top-level key.
     *
     *   auto   — no sudo when the ssh user owns (or can create) the key dir, else `sudo -n`,
     *   always — `sudo -n` for the dir and the group change (a box whose key dir is root's),
     *   never  — plain commands only (a key dir inside the ssh user's home, e.g. a Mac).
     *
     * Returns null for anything else.
     */
    private function resolveSudo(): ?string
    {
        $value = $this->option('sudo');
        $value = $value !== null && $value !== '' ? $value : ($this->environmentSetting('sudo') ?? config('env-secrets.sudo'));

        return match ($value) {
            null, '', 'auto' => 'auto',
            true, 'always' => 'always',
            false, 'never' => 'never',
            default => null,
        };
    }

    /**
     * Write the key to <path> on the server: create the dir, stream the key in over ssh stdin to a
     * temp file beside it, lock that down, then rename it over the old key.
     *
     * Without a group that is 700/600 owned by the connecting user — only that one person can
     * ever run `secrets:edit` against the box. With one, the directory becomes 750 and the key
     * 640 owned by `<user>:<group>`, which is what lets a team share it: `fetchKey()` is a plain
     * `ssh <host> cat`, with no sudo to fall back on. Re-provisioning re-applies whichever of the
     * two is configured, so a key rotation cannot silently lock the rest of the team out.
     *
     * The rename is what keeps an existing key safe: until the new one is complete and locked down
     * the old file is untouched, so a failed install never leaves a truncated key behind.
     */
    private function install(string $key, string $host, string $path, ?string $group, string $sudo): bool
    {
        $result = Process::input($key)->run(['ssh', $host, $this->installScript($path, $group, $sudo)]);

        if (! $result->successful()) {
            $this->error("Failed to install key on {$host}: ".trim($result->errorOutput()));

            if (str_contains($result->errorOutput(), 'sudo')) {
                $this->line("  The key dir needs root on {$host} and sudo wants a password. Pre-create "
                    .dirname($path).' owned by the ssh user (then no sudo is needed), or give it passwordless sudo.');
            }
        }

        return $result->successful();
    }

    /**
     * The remote half of install(), for the ssh user's login shell (sh, bash or zsh — hence a
     * function rather than a `$sudo` variable, which zsh would not word-split).
     *
     * Only two steps can ever need root: creating the key dir under a root-owned parent (/etc), and
     * handing the key to a group the ssh user is not in. Everything else — writing the key into a
     * dir the user now owns, the chmods, the rename — is plain. So "auto" takes the plain route
     * whenever the user owns the dir (or can create it) and, with a group, is a member of it; only
     * otherwise does it reach for `sudo -n`, which fails fast instead of waiting on a password
     * prompt that a non-interactive ssh can never answer.
     */
    private function installScript(string $path, ?string $group, string $sudo): string
    {
        $g = $group === null ? null : escapeshellarg($group);

        $script = sprintf('set -e; u=$(id -un); d=%s; k=%s; t=%s; ',
            escapeshellarg(dirname($path)), escapeshellarg($path), escapeshellarg("{$path}.tmp"));

        if ($g !== null) {
            $script .= "{ getent group {$g} || dscl . -read /Groups/{$g}; } >/dev/null 2>&1 "
                ."|| { echo \"group {$group} does not exist on this host\" >&2; exit 1; }; ";
        }

        $plain = ($g === null ? 'mkdir -p "$d"; chmod 700 "$d"' : "mkdir -p \"\$d\"; chgrp {$g} \"\$d\"; chmod 750 \"\$d\"")
            .'; priv() { "$@"; }';

        $elevated = ($g === null
            ? 'sudo -n install -d -m 700 -o "$u" -g "$(id -gn)" "$d"'
            : "sudo -n install -d -m 750 -o \"\$u\" -g {$g} \"\$d\"")
            .'; priv() { sudo -n "$@"; }';

        $owned = '{ [ -d "$d" ] || mkdir -p "$d" 2>/dev/null; } && [ -O "$d" ]'
            .($g === null ? '' : " && id -Gn | tr ' ' '\\n' | grep -qxF {$g}");

        $script .= match ($sudo) {
            'never' => $plain,
            'always' => $elevated,
            default => "if {$owned}; then {$plain}; else {$elevated}; fi",
        };

        return $script.'; umask 077; cat > "$t"; '
            .($g === null ? 'chmod 600 "$t"' : "priv chgrp {$g} \"\$t\"; chmod 640 \"\$t\"")
            .'; mv -f "$t" "$k"';
    }

    /**
     * The install failed, but .env.<env>.encrypted is already encrypted with the new key — the one
     * copy of it is in this process. Never let it die here: hand it to the clipboard with the
     * command to install it by hand. Only when there is no clipboard either is the key unrecoverable,
     * and then the committed file is put back the way it was, so nothing changed at all.
     */
    private function salvage(string $env, string $key, ?string $group, ?string $previous): int
    {
        $host = $this->resolveHost();
        $path = $this->keyPath($env);
        $slug = $this->resolveSlug();

        $this->newLine();

        if ($this->toClipboard($key)) {
            $this->warn("The key is NOT on {$host}, but .env.{$env}.encrypted is already encrypted with it. It is on your clipboard:");
            $this->line("→ Paste it into your password manager now (item: {$slug} · {$env}).");
            $this->line('→ Install it by hand, straight from the clipboard:');
            $this->line("    pbpaste | ssh {$host} '".$this->manualScript($path, $group)."'");
            $this->line("→ Then commit .env.{$env}.encrypted. (Re-running secrets:provision mints a different key instead.)");

            return self::FAILURE;
        }

        $previous === null
            ? @unlink($this->encryptedPath($env))
            : file_put_contents($this->encryptedPath($env), $previous);

        $this->warn("No clipboard to save the new key to, so it was discarded and .env.{$env}.encrypted "
            .($previous === null ? 'removed' : 'restored').' — nothing changed. Fix the install and re-run.');

        return self::FAILURE;
    }

    /**
     * The plain install, readable, for a human to paste. Every part is already validated (absolute
     * [A-Za-z0-9._/-] dir, [a-z0-9-] slug and env, unix group name), so none of it needs quoting.
     */
    private function manualScript(string $path, ?string $group): string
    {
        $dir = dirname($path);

        return $group === null
            ? "umask 077; mkdir -p {$dir} && chmod 700 {$dir} && cat > {$path} && chmod 600 {$path}"
            : "umask 077; mkdir -p {$dir} && chgrp {$group} {$dir} && chmod 750 {$dir} && cat > {$path} && chgrp {$group} {$path} && chmod 640 {$path}";
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
