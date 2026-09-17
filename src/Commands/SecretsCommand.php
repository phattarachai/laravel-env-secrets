<?php

namespace Phattarachai\EnvSecrets\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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
     * Explicit --host, else config('env-secrets.host'), else a sensible fallback.
     */
    protected function resolveHost(): string
    {
        return (string) ($this->option('host') ?: config('env-secrets.host') ?: 'necta');
    }

    /**
     * Explicit --dir, else config('env-secrets.dir'), else a sensible fallback.
     */
    protected function resolveDir(): string
    {
        return (string) ($this->option('dir') ?: config('env-secrets.dir') ?: '/etc/secrets');
    }

    /**
     * Explicit --slug, else config('env-secrets.slug'), else a slug of the app
     * name, else the application directory name.
     */
    protected function resolveSlug(): string
    {
        $slug = $this->option('slug') ?: config('env-secrets.slug');

        if (! $slug) {
            $slug = Str::slug((string) config('app.name')) ?: basename(base_path());
        }

        return (string) $slug;
    }

    /**
     * Deployed application directory on the box (for --remote reads). Explicit --path,
     * else config('env-secrets.app_path'). Null disables remote reads.
     */
    protected function resolveAppPath(): ?string
    {
        $path = ($this->hasOption('path') ? $this->option('path') : null) ?: config('env-secrets.app_path');

        return $path ? rtrim((string) $path, '/') : null;
    }

    protected function keyPath(string $env): string
    {
        return sprintf('%s/%s.%s.key', rtrim($this->resolveDir(), '/'), $this->resolveSlug(), $env);
    }

    /**
     * Explicit --group, else config('env-secrets.group'), else null (key stays owned by
     * the ssh user alone). A group is what lets more than one teammate run these commands:
     * the key is fetched with a plain `ssh <host> cat`, never sudo.
     */
    protected function resolveGroup(): ?string
    {
        $group = $this->hasOption('group') ? $this->option('group') : null;

        return ($group ?: config('env-secrets.group')) ?: null;
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

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/s', $value, $q) === 1) {
            return (string) preg_replace_callback(
                '/\\\\(.)/s',
                fn (array $e) => ['n' => "\n", 'r' => "\r", 't' => "\t", 'f' => "\f", 'v' => "\v", '"' => '"', "'" => "'", '\\' => '\\'][$e[1]] ?? $e[0],
                $q[1],
            );
        }

        if (preg_match("/^'([^']*)'$/s", $value, $q) === 1) {
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
