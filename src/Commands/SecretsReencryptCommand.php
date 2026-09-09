<?php

namespace Phattarachai\EnvSecrets\Commands;

/**
 * The write side of the edit loop: re-encrypt an edited .env.<env> with the key ALREADY installed on
 * the box — no new key is minted. It then verifies the round-trip (decrypts the fresh ciphertext in
 * memory and checks it matches the plaintext) before you commit, and optionally prunes the plaintext.
 *
 * This is the command that closes the footgun behind `php artisan env:encrypt`: that command reads
 * the key ONLY from --key (never LARAVEL_ENV_ENCRYPTION_KEY) and, run non-interactively, silently
 * mints a throwaway random key — producing ciphertext the box can no longer decrypt. Here the key
 * comes from the box and is always passed through, so re-encrypting can never drift the key.
 */
class SecretsReencryptCommand extends SecretsCommand
{
    protected $signature = 'secrets:reencrypt
        {env : Environment to re-encrypt with the key already on the box (e.g. uat, production)}
        {--host= : SSH host alias that stores the key (default: config env-secrets.host)}
        {--dir= : Directory on the box holding the key files (default: config env-secrets.dir)}
        {--slug= : Filename stem — <slug>.<env>.key (default: config, else the app name)}
        {--prune : Delete the plaintext .env.<env> after a verified re-encrypt}';

    protected $description = 'Re-encrypt .env.<env> with the key already on the box and verify the round-trip (never prints the key).';

    public function handle(): int
    {
        $env = (string) $this->argument('env');

        if (! $this->validEnv($env)) {
            return self::FAILURE;
        }

        $plaintext = base_path(".env.{$env}");

        if (! file_exists($plaintext)) {
            $this->error("Nothing to encrypt: .env.{$env} does not exist — run `secrets:edit {$env}` first.");

            return self::FAILURE;
        }

        $key = $this->fetchKey($env);

        if ($key === null) {
            return self::FAILURE;
        }

        if ($this->encrypt($env, $key) !== self::SUCCESS) {
            $this->error('env:encrypt failed.');

            return self::FAILURE;
        }

        if (! $this->verifies($env, $plaintext, $key)) {
            $this->error("Round-trip check failed: .env.{$env}.encrypted does not decrypt back to the plaintext. Do not commit it.");

            return self::FAILURE;
        }

        $this->info(".env.{$env} re-encrypted → .env.{$env}.encrypted (round-trip verified)");

        $this->prune($env, $plaintext);

        $this->line("→ Commit .env.{$env}.encrypted.");

        return self::SUCCESS;
    }

    private function verifies(string $env, string $plaintext, string $key): bool
    {
        $roundTrip = $this->decryptInMemory($env, $key);

        return $roundTrip !== null
            && hash_equals((string) file_get_contents($plaintext), $roundTrip);
    }

    private function prune(string $env, string $plaintext): void
    {
        if (! $this->option('prune')) {
            return;
        }

        @unlink($plaintext);
        $this->line("→ Removed plaintext .env.{$env} (--prune).");
    }
}
