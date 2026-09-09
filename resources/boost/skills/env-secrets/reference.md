# Env Secrets — reference

Full detail behind [`SKILL.md`](SKILL.md): the footgun this package prevents, the security model, the
command surface, and recovery.

## The env:encrypt footgun

Laravel's `env:encrypt` and `env:decrypt` are asymmetric about where the key comes from:

```php
// EnvironmentEncryptCommand::handle()
$key = $this->option('key');                 // ONLY --key
if (! $key && $this->input->isInteractive()) { /* prompt: generate or ask */ }
if ($key === null) { $key = Encrypter::generateKey($cipher); }   // random, throwaway

// EnvironmentDecryptCommand::handle()
$key = $this->option('key') ?: Env::get('LARAVEL_ENV_ENCRYPTION_KEY');   // env var honored
```

So `env:encrypt` **never** reads `LARAVEL_ENV_ENCRYPTION_KEY`, and when run non-interactively (a script, a
pipe, CI) it silently mints a random key and encrypts with that. The success line echoes the key, so a
`>/dev/null` throws it away for good. The result is a committed `.env.<env>.encrypted` that the deploy
box's key cannot decrypt — surfacing as **"The MAC is invalid"** at the next `env:decrypt`. `env:decrypt`
fail-safes (it keeps the current `.env` when the MAC check fails), so the running app survives, but the
deploy of that env is broken until the ciphertext is fixed.

`secrets:reencrypt` closes this: it fetches the box key and passes it to `env:encrypt` as `--key` every
time (in-process, so `--key` never hits argv/`ps`), then decrypts the fresh ciphertext in memory and
asserts it matches the plaintext before you commit. The key cannot drift.

## Security model

- The key lives only at `<dir>/<slug>.<env>.key` on the deploy box (`600`, owned by the ssh user). It is
  **not** in the repo, **not** a GitHub secret, **not** in any `.env`.
- Every command fetches it over ssh into process memory and uses it in-process. It is never printed, never
  written to a world-readable file, and never passed as a shell argument — so it stays out of argv, `ps`,
  shell history, and CI logs. Tests assert this (`Process::assertRan` checks the key is not in any argv;
  a dedicated test asserts it is absent from command output).
- `secrets:provision` streams a freshly minted key to the box over ssh **stdin** and to the clipboard over
  `pbcopy` **stdin** — again never an argument.
- `secrets:show` decrypts in memory and masks values unless one is explicitly named. `--remote` reads the
  already-plaintext live `.env` on the box; no key is involved.

## Command surface

| Command | Key source | Writes plaintext? | Purpose |
|---|---|---|---|
| `secrets:provision <env>` | mints a new one | requires `.env.<env>` present | first-time encrypt + install key on box |
| `secrets:edit <env>` | box | yes → `.env.<env>` | decrypt for editing |
| `secrets:reencrypt <env>` | box | no (`--prune` deletes it) | re-encrypt edited env + verify round-trip |
| `secrets:status <env>` | box (local) / none (`--remote`) | no | health check |
| `secrets:show <env> [name]` | box (local) / none (`--remote`) | no | inspect names / one value |

Shared options: `--host`, `--dir`, `--slug` (all commands); `--path` (`status`/`show`, for `--remote`).

Shared plumbing lives in `Phattarachai\EnvSecrets\Commands\SecretsCommand` (the abstract base):
`fetchKey()` (ssh read), `encrypt()` (silent in-process `env:encrypt --key`), `decryptInMemory()`,
`readRemoteEnv()`, `parseEnv()`, and the host/dir/slug/app_path resolvers.

## Recovering a broken (MAC-invalid) encrypted file

If a hand-run `env:encrypt` (or any wrong-key encrypt) already landed in git:

1. Recover the last good ciphertext: `git show <good-sha>:.env.<env>.encrypted > .env.<env>.encrypted`.
2. Confirm the box key decrypts it: `php artisan secrets:status <env>` → `Decrypts OK`.
3. Now redo the intended change the correct way: `secrets:edit <env>` → edit → `secrets:reencrypt <env>`.
4. Commit the fixed `.env.<env>.encrypted`.

No production breakage occurs in the meantime as long as the deploy resets to the branch ref and
`env:decrypt` keeps the current `.env` on a MAC failure — but the env's deploy stays broken until the
ciphertext matches the box key again.

## Editing an env for a project — the loop an agent should follow

1. `php artisan secrets:edit <env>` — never `env:decrypt` by hand (you would have to source the key
   yourself, defeating the point).
2. Edit `.env.<env>` (add/rotate keys). Do not touch `.env.<env>.encrypted` directly.
3. `php artisan secrets:reencrypt <env> --prune`.
4. Commit `.env.<env>.encrypted` only. The plaintext `.env.<env>` is gitignored and (with `--prune`) gone.
