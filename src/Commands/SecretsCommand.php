<?php

namespace Phattarachai\EnvSecrets\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Phattarachai\EnvSecrets\Exceptions\UnterminatedQuote;

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
     * Every box setting resolves the same way, most specific first:
     *
     *   1. the explicit CLI option (--host, --dir, --slug, --group, --path),
     *   2. config('env-secrets.environments.<env>.<setting>') — the per-env override,
     *   3. config('env-secrets.<setting>') — the project-wide default,
     *   4. the resolver's own fallback.
     *
     * The per-env map is what lets one project keep production on one box and staging on another
     * without anyone having to remember --host. A forgotten flag reaches the WRONG box, and "the key
     * file happens to be missing there" is the only thing standing between that and a bad day.
     */
    protected function setting(string $setting, ?string $option = null): mixed
    {
        $option ??= $setting;
        $explicit = $this->hasOption($option) ? $this->option($option) : null;

        return $explicit ?: $this->environmentSetting($setting) ?: config("env-secrets.{$setting}");
    }

    /**
     * config('env-secrets.environments.<env>.<setting>'), read by array key rather than dot notation
     * so an env name is never re-interpreted as a config path before validEnv() has seen it.
     */
    protected function environmentSetting(string $setting): mixed
    {
        if (! $this->hasArgument('env')) {
            return null;
        }

        $environments = config('env-secrets.environments');
        $overrides = is_array($environments) ? ($environments[(string) $this->argument('env')] ?? null) : null;

        return is_array($overrides) ? ($overrides[$setting] ?? null) : null;
    }

    /**
     * The ssh host alias of the box that stores this env's key — see setting() for the order.
     */
    protected function resolveHost(): string
    {
        return (string) ($this->setting('host') ?: 'necta');
    }

    /**
     * The directory on the box that holds the key files — see setting() for the order.
     */
    protected function resolveDir(): string
    {
        return (string) ($this->setting('dir') ?: '/etc/secrets');
    }

    /**
     * The key filename stem — see setting() for the order — else a slug of the app name, else the
     * application directory name.
     */
    protected function resolveSlug(): string
    {
        $slug = $this->setting('slug');

        if (! $slug) {
            $slug = Str::slug((string) config('app.name')) ?: basename(base_path());
        }

        return (string) $slug;
    }

    /**
     * Deployed application directory on the box (for --remote reads) — see setting() for the order;
     * the option is --path. Null disables remote reads.
     */
    protected function resolveAppPath(): ?string
    {
        $path = $this->setting('app_path', 'path');

        return $path ? rtrim((string) $path, '/') : null;
    }

    protected function keyPath(string $env): string
    {
        return sprintf('%s/%s.%s.key', rtrim($this->resolveDir(), '/'), $this->resolveSlug(), $env);
    }

    /**
     * The unix group granted read access to the key — see setting() for the order — else null (key
     * stays owned by the ssh user alone). A group is what lets more than one teammate run these
     * commands: the key is fetched with a plain `ssh <host> cat`, never sudo.
     */
    protected function resolveGroup(): ?string
    {
        $group = $this->setting('group');

        return $group ? (string) $group : null;
    }

    /**
     * Where a setting's value came from, in setting()'s terms — printed next to it so "why is this
     * going to that box" answers itself.
     */
    protected function settingSource(string $setting, ?string $option = null, string $fallback = 'default'): string
    {
        $option ??= $setting;

        return match (true) {
            $this->hasOption($option) && (bool) $this->option($option) => "--{$option}",
            (bool) $this->environmentSetting($setting) => "environments.{$this->argument('env')}",
            (bool) config("env-secrets.{$setting}") => "env-secrets.{$setting}",
            default => $fallback,
        };
    }

    /**
     * Say which box and which file this run is about to touch, before it touches anything — so a
     * wrong host is obvious on the first line rather than inferred from an error. It goes to stderr:
     * `secrets:show <env> <NAME>` prints a bare value that scripts capture from stdout.
     */
    protected function announce(string $host, string $path): void
    {
        $this->output->getErrorStyle()->writeln("<comment>Box</comment>  {$host}:{$path}");
    }

    /**
     * announce() for the file this run reads on the box: the env's key, or with --remote the live
     * .env. Silent when --remote has no app path — the command refuses on the next line anyway.
     */
    protected function announceTarget(string $env, bool $remote = false): void
    {
        $path = $remote ? $this->resolveAppPath() : $this->keyPath($env);

        if ($path !== null) {
            $this->announce($this->resolveHost(), $remote ? "{$path}/.env" : $path);
        }
    }

    /**
     * A unix group name: [a-z_][a-z0-9_-]*, at most 32 chars.
     */
    protected function isGroup(string $group): bool
    {
        return (bool) preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $group);
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
        $file = $this->encryptedPath($env);

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

    protected function envPath(string $env): string
    {
        return base_path(".env.{$env}");
    }

    protected function encryptedPath(string $env): string
    {
        return base_path(".env.{$env}.encrypted");
    }

    /**
     * Read an env file into key => the ORIGINAL assignment, verbatim.
     *
     * parseEnv() is the wrong tool for anything that writes: it trims values and throws the source
     * line away, so a value round-tripped through it loses its quoting and inline comments. This
     * keeps the raw text so it can be appended byte-for-byte. Last assignment wins, matching what a
     * dotenv reader ends up with.
     *
     * A double-quoted value may span real newlines — a PEM key is the usual one — and phpdotenv
     * reads it as a single value. Each physical line cannot be judged on its own, then: a
     * continuation is swallowed into the assignment that opened the quote, and the entry's value is
     * every line of it joined back with the separator the file used.
     *
     * @return array<string, string>
     */
    protected function readEnvLines(string $contents): array
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = [];
        $name = null;

        foreach (preg_split('/\r\n|\r|\n/', $this->withoutBom($contents)) ?: [] as $line) {
            if ($name !== null) {
                $lines[$name] .= $eol.$line;

                if (! $this->hasOpenQuote($lines[$name])) {
                    $name = null;
                }

                continue;
            }

            $name = $this->nameOf($line);

            if ($name === null) {
                continue;
            }

            $lines[$name] = $line;

            if (! $this->hasOpenQuote($line)) {
                $name = null;
            }
        }

        // A quote still open at EOF means every assignment after it was swallowed into one entry.
        // Returning that map would hide the swallowed keys from the protected-key check AND append
        // them, verbatim, as one dead block. Refusing is the only safe answer.
        if ($name !== null) {
            throw new UnterminatedQuote($name);
        }

        return $lines;
    }

    /**
     * A UTF-8 BOM is not whitespace, so without stripping it the file's FIRST key is invisible to
     * nameOf() — and a merge would then append a duplicate that shadows the developer's own value.
     * Editors on Windows write one routinely.
     */
    protected function withoutBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }

    /**
     * True when the assignment so far opens a double quote it has not closed — i.e. the value keeps
     * going on the next physical line. Escaped quotes do not count.
     */
    protected function hasOpenQuote(string $assignment): bool
    {
        if (preg_match('/^\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=\s*"/', $assignment) !== 1) {
            return false;
        }

        $value = (string) preg_replace('/^\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=\s*"/', '', $assignment);

        return preg_match('/^(?:[^"\\\\]|\\\\.)*"/s', $value) !== 1;
    }

    /**
     * The variable a raw line assigns, or null when the line is blank, a comment, or not an
     * assignment. `export FOO=1` counts, `FOO` alone does not.
     */
    protected function nameOf(string $line): ?string
    {
        return preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m) === 1 ? $m[1] : null;
    }

    /**
     * The value an assignment carries, unquoted — used only to COMPARE two assignments, never to
     * write one. Quoted values keep their inner `#`; bare values stop at an inline comment, as
     * dotenv does.
     *
     * The closing quote is anchored to the end of the assignment — bar a trailing inline comment —
     * so `A="x"junk` is not read as `x`. Without the anchor two different values compare equal.
     *
     * The unescaping is deliberately phpdotenv's exact set and not stripcslashes(): the latter also
     * eats the backslash of every unrecognised escape and expands \x41 and \101, which would make
     * `"C:\path"` and `"C:path"` compare EQUAL. A false "same" is the worst outcome this command
     * has — it silently skips a key the developer needed.
     */
    protected function valueOf(string $line): string
    {
        if (preg_match('/^\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=\s*(.*)$/s', $line, $m) !== 1) {
            return '';
        }

        $value = rtrim($m[1]);

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"(?:\\s+#.*)?$/s', $value, $q) === 1) {
            return (string) preg_replace_callback(
                '/\\\\(.)/s',
                fn (array $e) => ['n' => "\n", 'r' => "\r", 't' => "\t", 'f' => "\f", 'v' => "\v", '"' => '"', "'" => "'", '\\' => '\\'][$e[1]] ?? $e[0],
                $q[1],
            );
        }

        if (preg_match("/^'([^']*)'(?:\\s+#.*)?$/s", $value, $q) === 1) {
            return $q[1];
        }

        return trim((string) preg_split('/\s+#/', $value, 2)[0]);
    }

    /**
     * Enough of a value to recognise it, never enough to use it. Every command that prints a value
     * in bulk goes through this, so a secret cannot reach the scrollback or a CI log.
     */
    protected function mask(string $value): string
    {
        $length = strlen($value);

        return match (true) {
            $length === 0 => '(empty)',
            $length <= 4 => str_repeat('*', $length),
            default => substr($value, 0, 2).str_repeat('*', min($length - 2, 8)),
        };
    }
}
