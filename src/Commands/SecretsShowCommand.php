<?php

namespace Phattarachai\EnvSecrets\Commands;

/**
 * Peek at a provisioned env without ever writing plaintext to disk.
 *
 * With no {name}: list the variable NAMES with masked values — a safe overview.
 * With {name}: print that ONE variable's value — an explicit, per-value opt-in so the whole file is
 * never dumped by accident.
 *
 * Local (default): fetch the key and decrypt the committed .encrypted in memory.
 * --remote: read the live, deployed .env on the server (what is actually running).
 */
class SecretsShowCommand extends SecretsCommand
{
    protected $signature = 'secrets:show
        {env : Environment to inspect (e.g. uat, production)}
        {name? : A single variable to reveal; omit to list names with masked values}
        {--host= : SSH host alias that stores the key (default: config env-secrets.host)}
        {--dir= : Directory on the box holding the key files (default: config env-secrets.dir)}
        {--slug= : Filename stem — <slug>.<env>.key (default: config, else the app name)}
        {--path= : Deployed app directory on the box for --remote (default: config env-secrets.app_path)}
        {--remote : Read the live deployed .env on the server instead of the committed .encrypted}';

    protected $description = 'Inspect .env.<env> without writing plaintext to disk — masked names by default, one value when named.';

    public function handle(): int
    {
        $env = (string) $this->argument('env');

        if (! $this->validEnv($env)) {
            return self::FAILURE;
        }

        $contents = $this->option('remote') ? $this->readRemoteEnv() : $this->decryptLocally($env);

        if ($contents === null) {
            return self::FAILURE;
        }

        $vars = $this->parseEnv($contents);
        $name = $this->argument('name');

        return $name === null ? $this->listNames($env, $vars) : $this->reveal($env, $vars, (string) $name);
    }

    private function decryptLocally(string $env): ?string
    {
        $key = $this->fetchKey($env);

        if ($key === null) {
            return null;
        }

        $contents = $this->decryptInMemory($env, $key);

        if ($contents === null) {
            $this->error("Could not decrypt .env.{$env}.encrypted with the box key.");
        }

        return $contents;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function listNames(string $env, array $vars): int
    {
        foreach ($vars as $name => $value) {
            $this->line(sprintf('%-32s %s', $name, $this->mask($value)));
        }

        $this->newLine();
        $this->line("→ Reveal one value: php artisan secrets:show {$env} <NAME>");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function reveal(string $env, array $vars, string $name): int
    {
        if (! array_key_exists($name, $vars)) {
            $this->error("{$name} is not set in .env.{$env}.");

            return self::FAILURE;
        }

        $this->line($vars[$name]);

        return self::SUCCESS;
    }

    private function mask(string $value): string
    {
        $length = strlen($value);

        return match (true) {
            $length === 0 => '(empty)',
            $length <= 4 => str_repeat('*', $length),
            default => substr($value, 0, 2).str_repeat('*', min($length - 2, 8)),
        };
    }
}
