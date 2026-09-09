<?php

namespace Phattarachai\EnvSecrets\Commands;

/**
 * Health check for a provisioned env — no secret values printed, no plaintext left on disk.
 *
 * Local (default): the key is present on the box, .env.<env>.encrypted is committed, and the box key
 * actually decrypts it (valid MAC). Proves the deploy will be able to decrypt.
 *
 * --remote: read the live, deployed .env on the server (what is actually running) and report that it
 * is present and how many variables it holds.
 */
class SecretsStatusCommand extends SecretsCommand
{
    protected $signature = 'secrets:status
        {env : Environment to check (e.g. uat, production)}
        {--host= : SSH host alias that stores the key (default: config env-secrets.host)}
        {--dir= : Directory on the box holding the key files (default: config env-secrets.dir)}
        {--slug= : Filename stem — <slug>.<env>.key (default: config, else the app name)}
        {--path= : Deployed app directory on the box for --remote (default: config env-secrets.app_path)}
        {--remote : Check the live deployed .env on the server instead of the committed .encrypted}';

    protected $description = 'Report whether .env.<env> is provisioned and decryptable — locally, or the live .env with --remote.';

    public function handle(): int
    {
        $env = (string) $this->argument('env');

        if (! $this->validEnv($env)) {
            return self::FAILURE;
        }

        return $this->option('remote') ? $this->remoteStatus() : $this->localStatus($env);
    }

    private function localStatus(string $env): int
    {
        $key = $this->fetchKey($env);

        if ($key === null) {
            return self::FAILURE;
        }

        $encrypted = base_path(".env.{$env}.encrypted");
        $hasEncrypted = file_exists($encrypted);
        $decrypts = $hasEncrypted && $this->decryptInMemory($env, $key) !== null;

        $this->line("Host          {$this->resolveHost()}");
        $this->line("Key           {$this->keyPath($env)}  {$this->mark(true)}");
        $this->line(".encrypted    .env.{$env}.encrypted  {$this->mark($hasEncrypted)}");
        $this->line("Decrypts      {$this->mark($decrypts)}".($decrypts ? '' : '  (box key does not match the committed file)'));

        return $decrypts ? self::SUCCESS : self::FAILURE;
    }

    private function remoteStatus(): int
    {
        $contents = $this->readRemoteEnv();

        if ($contents === null) {
            return self::FAILURE;
        }

        $count = count($this->parseEnv($contents));

        $this->line("Host          {$this->resolveHost()}");
        $this->line("Live .env     {$this->resolveAppPath()}/.env  {$this->mark(true)}  ({$count} variables)");

        return self::SUCCESS;
    }

    private function mark(bool $ok): string
    {
        return $ok ? '<info>OK</info>' : '<error>MISSING</error>';
    }
}
