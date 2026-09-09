<?php

namespace Phattarachai\EnvSecrets\Commands;

/**
 * The read side of the edit loop: fetch the installed key from the box and decrypt
 * .env.<env>.encrypted back to a plaintext .env.<env> for editing. Follow with `secrets:reencrypt`
 * to seal it again with the same key.
 *
 * The key is fetched over ssh and used in-process — never printed, never an argument.
 */
class SecretsEditCommand extends SecretsCommand
{
    protected $signature = 'secrets:edit
        {env : Environment to decrypt for editing (e.g. uat, production)}
        {--host= : SSH host alias that stores the key (default: config env-secrets.host)}
        {--dir= : Directory on the box holding the key files (default: config env-secrets.dir)}
        {--slug= : Filename stem — <slug>.<env>.key (default: config, else the app name)}';

    protected $description = 'Fetch the key from the box and decrypt .env.<env> for editing (never prints the key).';

    public function handle(): int
    {
        $env = (string) $this->argument('env');

        if (! $this->validEnv($env)) {
            return self::FAILURE;
        }

        $key = $this->fetchKey($env);

        if ($key === null) {
            return self::FAILURE;
        }

        if ($this->decrypt($env, $key) !== self::SUCCESS) {
            $this->error("env:decrypt failed — is .env.{$env}.encrypted committed?");

            return self::FAILURE;
        }

        $this->info(".env.{$env}.encrypted → .env.{$env}");
        $this->line("→ Edit .env.{$env}, then run: php artisan secrets:reencrypt {$env}");

        return self::SUCCESS;
    }

    private function decrypt(string $env, string $key): int
    {
        return $this->callSilently('env:decrypt', [
            '--env' => $env,
            '--key' => $key,
            '--force' => true,
        ]);
    }
}
