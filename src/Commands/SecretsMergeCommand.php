<?php

namespace Phattarachai\EnvSecrets\Commands;

use Illuminate\Support\Str;

/**
 * Top up a local .env with keys it is missing, from a committed .env.<env>.encrypted.
 *
 * The problem it solves: a teammate pulls a commit that adds a new secret and their .env, written
 * months ago, has no such key. `secrets:merge` appends what is missing so `git pull` + `composer sync`
 * is enough.
 *
 * Three rules keep it safe to run unattended:
 *   1. A key already present in the target is SKIPPED, whatever its value — a local override is never
 *      clobbered silently. Only --replace changes that, and only with a confirmation or an explicit
 *      key list.
 *   2. Keys matching config('env-secrets.merge.protected') are NEVER written, not even with
 *      --replace --force. That is the seatbelt for the day someone points this at a deploy env:
 *      APP_KEY, DB_* and friends stay whatever the developer's machine already had.
 *   3. An appended line is written VERBATIM — the exact source line, comments, quoting and all.
 *      Nothing is re-serialised, so `FOO="a b # c"` survives the trip.
 *
 * Nothing is decrypted to disk (decryptInMemory), the key is never printed, and --dry-run reports
 * every decision with masked values so a `composer sync` can never dump secrets into scrollback.
 */
class SecretsMergeCommand extends SecretsCommand
{
    protected $signature = 'secrets:merge
        {env : Environment to merge from (e.g. local, qas, production)}
        {--host= : SSH host alias that stores the key (default: config env-secrets.host)}
        {--dir= : Directory on the box holding the key files (default: config env-secrets.dir)}
        {--slug= : Filename stem — <slug>.<env>.key (default: config, else the app name)}
        {--into=.env : The file to merge into, relative to the project root unless absolute}
        {--replace= : Overwrite keys that already exist — bare to overwrite all (asks first), or a comma-separated list to target only those}
        {--force : Skip the --replace confirmation (for non-interactive runs)}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Append secrets missing from a local .env, from the committed .env.<env>.encrypted (never overwrites without --replace).';

    public function handle(): int
    {
        $env = (string) $this->argument('env');

        if (! $this->validEnv($env)) {
            return self::FAILURE;
        }

        $target = $this->resolveTarget();

        if (! file_exists($target)) {
            $this->error("Nothing to merge into: {$target} does not exist.");

            return self::FAILURE;
        }

        $key = $this->fetchKey($env);

        if ($key === null) {
            return self::FAILURE;
        }

        $source = $this->decryptInMemory($env, $key);

        if ($source === null) {
            $this->error("Could not decrypt .env.{$env}.encrypted with the box key.");

            return self::FAILURE;
        }

        return $this->merge($env, $source, $target);
    }

    private function merge(string $env, string $source, string $target): int
    {
        $incoming = $this->readEnvLines($source);
        $existing = $this->readEnvLines((string) file_get_contents($target));

        $plan = $this->plan($incoming, $existing);

        $this->report($plan, $incoming, $existing);

        $writes = array_filter($plan, fn (string $verdict) => in_array($verdict, ['add', 'replace'], true));

        if ($writes === []) {
            $this->line("→ Nothing to merge from .env.{$env}.encrypted into ".$this->relative($target).'.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('→ --dry-run: nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->confirmReplacements($plan)) {
            $this->line('→ Aborted; nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->write($target, $incoming, $plan)) {
            $this->error("Could not write {$target}.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('%d key(s) merged into %s (backup: %s).', count($writes), $this->relative($target), $this->relative($this->backupPath($target))));

        return self::SUCCESS;
    }

    /**
     * Decide a verdict per incoming key, in source order.
     *
     * add      — missing from the target, will be appended verbatim
     * replace  — present, differs, and --replace covers it
     * same     — present with an identical value; nothing to do
     * differs  — present with a different value; left alone (report THAT it differs, never how)
     * protected— blocked by config('env-secrets.merge.protected'); never written
     *
     * @param  array<string, string>  $incoming
     * @param  array<string, string>  $existing
     * @return array<string, string>
     */
    private function plan(array $incoming, array $existing): array
    {
        $replaceAll = $this->replaceRequested() && $this->replaceKeys() === null;
        $replaceKeys = $this->replaceKeys() ?? [];

        $plan = [];

        foreach ($incoming as $name => $line) {
            $plan[$name] = match (true) {
                $this->isProtected($name) => 'protected',
                ! array_key_exists($name, $existing) => 'add',
                $this->valueOf($line) === $this->valueOf($existing[$name]) => 'same',
                $replaceAll || in_array($name, $replaceKeys, true) => 'replace',
                default => 'differs',
            };
        }

        return $plan;
    }

    /**
     * @param  array<string, string>  $plan
     * @param  array<string, string>  $incoming
     * @param  array<string, string>  $existing
     */
    private function report(array $plan, array $incoming, array $existing): void
    {
        foreach ($plan as $name => $verdict) {
            $note = match ($verdict) {
                'add' => $this->mask($this->valueOf($incoming[$name])),
                'replace' => sprintf('%s → %s', $this->mask($this->valueOf($existing[$name])), $this->mask($this->valueOf($incoming[$name]))),
                'same' => '(already set)',
                'differs' => '(already set, differs — left alone)',
                default => '(protected — never merged)',
            };

            $this->line(sprintf('%-9s %-32s %s', $verdict === 'protected' ? 'skip' : $verdict, $name, $note));
        }
    }

    /**
     * A --replace run that targets everything is the one way this command can destroy a developer's
     * value, so it asks — unless --force, or unless the keys were named explicitly.
     *
     * @param  array<string, string>  $plan
     */
    private function confirmReplacements(array $plan): bool
    {
        $replacing = array_keys(array_filter($plan, fn (string $verdict) => $verdict === 'replace'));

        if ($replacing === [] || $this->option('force') || $this->replaceKeys() !== null) {
            return true;
        }

        return $this->confirm(sprintf('Overwrite %d existing key(s) in %s?', count($replacing), $this->relative($this->resolveTarget())), false);
    }

    /**
     * Append the additions and rewrite the replacements, then swap the file in one rename() — a
     * developer's .env is never left half-written. The previous contents land in .env.backup first.
     *
     * @param  array<string, string>  $incoming
     * @param  array<string, string>  $plan
     */
    private function write(string $target, array $incoming, array $plan): bool
    {
        $original = (string) file_get_contents($target);
        $contents = $original;

        foreach ($plan as $name => $verdict) {
            if ($verdict === 'replace') {
                $contents = $this->replaceLine($contents, $name, $incoming[$name]);
            }
        }

        $additions = array_keys(array_filter($plan, fn (string $verdict) => $verdict === 'add'));

        if ($additions !== []) {
            $contents = rtrim($contents, "\r\n")."\n\n".implode("\n", array_map(fn (string $name) => $incoming[$name], $additions))."\n";
        }

        if (@copy($target, $this->backupPath($target)) === false) {
            return false;
        }

        return $this->atomicallyReplace($target, $contents);
    }

    /**
     * Swap the line that assigns $name for the incoming one, verbatim. Only the LAST assignment is
     * rewritten, because that is the one a dotenv reader ends up with.
     */
    private function replaceLine(string $contents, string $name, string $line): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($this->nameOf($lines[$i]) === $name) {
                $lines[$i] = $line;

                break;
            }
        }

        return implode("\n", $lines);
    }

    private function atomicallyReplace(string $target, string $contents): bool
    {
        $temp = $target.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (@file_put_contents($temp, $contents) === false) {
            return false;
        }

        @chmod($temp, (fileperms($target) ?: 0100600) & 0777);

        if (! @rename($temp, $target)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    private function backupPath(string $target): string
    {
        return $target.'.backup';
    }

    /**
     * The file to merge into. Relative --into values resolve against the project root, so
     * `--into=.env` means the application's own .env and nothing outside the project.
     */
    private function resolveTarget(): string
    {
        $into = (string) ($this->option('into') ?: '.env');

        return str_starts_with($into, '/') ? $into : base_path($into);
    }

    private function relative(string $path): string
    {
        return Str::after($path, rtrim(base_path(), '/').'/');
    }

    /**
     * --replace was asked for at all: bare (ArrayInput hands us `true`, the CLI hands us `null`, so
     * the raw input is the tiebreaker) or with a key list.
     */
    private function replaceRequested(): bool
    {
        return $this->option('replace') !== null || $this->input->hasParameterOption('--replace', true);
    }

    /**
     * The explicit keys named by `--replace=A,B`, or null for "every key that differs".
     *
     * @return array<int, string>|null
     */
    private function replaceKeys(): ?array
    {
        $value = $this->option('replace');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function isProtected(string $name): bool
    {
        /** @var array<int, string> $patterns */
        $patterns = (array) config('env-secrets.merge.protected', []);

        return $patterns !== [] && Str::is($patterns, $name);
    }
}
