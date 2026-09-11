# Laravel Env Secrets

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/laravel-env-secrets.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-env-secrets)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-env-secrets/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/laravel-env-secrets/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-env-secrets/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/laravel-env-secrets/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/laravel-env-secrets/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/laravel-env-secrets)
![Laravel Version](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/laravel-env-secrets.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-env-secrets)

A small suite of artisan commands to run the encrypted-`.env` deploy pattern end to end: mint an
encryption key, encrypt `.env.<env>` with Laravel's own `env:encrypt`, install the key on your deploy
box over ssh — and then **edit, re-encrypt, health-check, and inspect** those files later without the key
ever drifting. The key is generated with a CSPRNG, kept only on the box, and **never** touches stdout,
your shell history, or a process argument — it reaches the server over ssh stdin and your clipboard via
`pbcopy`, and every later command fetches it back over ssh into memory only.

| Command | Purpose |
|---|---|
| `secrets:provision <env>` | First-time: mint a key, encrypt `.env.<env>`, install the key on the box. |
| `secrets:edit <env>` | Fetch the box key and decrypt `.env.<env>` for editing. |
| `secrets:reencrypt <env>` | Re-encrypt an edited `.env.<env>` with the **same** box key + verify the round-trip. |
| `secrets:status <env>` | Is it provisioned and does the box key decrypt it? (`--remote` checks the live `.env`.) |
| `secrets:show <env> [name]` | Inspect values without writing plaintext — masked names, or one named value. |

## Why

Committing a plaintext `.env.production` puts every credential your app has into git history forever.
The alternative most teams reach for — a secrets manager, Vault, SSM — is a whole moving part to run.

Laravel ships a middle ground: [`env:encrypt` / `env:decrypt`](https://laravel.com/docs/configuration#encrypting-environment-files).
You commit an **encrypted** `.env.<env>.encrypted`, keep the single decryption key off git, and decrypt
at deploy time. The secrets live in the repo (safe — they are ciphertext), and the only thing you have
to distribute out-of-band is one short key per environment.

The fiddly part is the key lifecycle: generating it safely, encrypting with *that* key (not a fresh one
`env:encrypt` prints to your terminal and scrollback), and getting it onto the box without it leaking
through a command argument or `ps`. `secrets:provision` does exactly that and nothing else.

## How the pattern works

1. You hold the plaintext `.env.uat` / `.env.production` locally (git-ignored — see below). These are the
   editable source of truth.
2. `secrets:provision <env>` mints a 32-hex-char key, runs `env:encrypt --key=… --env=<env>`, and produces
   `.env.<env>.encrypted`. **You commit that file.**
3. The same key is installed on the deploy box at `<dir>/<slug>.<env>.key` (mode `600`, owned by the ssh
   user) and copied to your clipboard to paste into your password manager as a backup.
4. Your deploy script exports the key from that file and runs `env:decrypt` to regenerate `.env` on the
   box before the app boots.

The key is the only secret that ever leaves your machine, and it only ever moves over ssh stdin.

## Install

```bash
composer require --dev phattarachai/laravel-env-secrets
```

It is a dev-time provisioning tool — you run it from a developer machine, so `--dev` is the right place
for it. The package auto-registers its service provider.

Publish the config to set your defaults:

```bash
php artisan vendor:publish --tag=env-secrets-config
```

```php
// config/env-secrets.php
return [
    'host'     => env('ENV_SECRETS_HOST', 'necta'),          // ssh host alias of the deploy box
    'dir'      => env('ENV_SECRETS_DIR', '/etc/nectapharma'), // where key files live on the box
    'slug'     => env('ENV_SECRETS_SLUG', null),              // key filename stem; null → app name
    'group'    => env('ENV_SECRETS_GROUP', null),             // unix group that may read the key; null → owner only
    'app_path' => env('ENV_SECRETS_APP_PATH', null),          // deployed app dir on the box, for --remote
];
```

Every value is overridable per run with `--host`, `--dir`, `--slug`, `--group`. When an option is omitted the command
uses the config value; when the config `slug` is `null` it derives one from `config('app.name')` (falling
back to the application directory name).

## Two infra snippets you add yourself

The package encrypts and distributes the key. The two ends of the pattern live in *your* repo and *your*
deploy pipeline — add them once.

**1. Git-ignore the plaintext env files** so only the `.encrypted` versions are ever committed. In `.gitignore`:

```gitignore
.env.production
.env.uat
```

(`.env.<env>.encrypted` is *not* ignored — that is the whole point; it gets committed.)

**2. Decrypt at deploy.** Before copying the decrypted file into place, export the key from the box and run
`env:decrypt`. In your deploy step (adjust `<dir>`, `<slug>`, `<env>`):

```bash
export LARAVEL_ENV_ENCRYPTION_KEY="$(cat /etc/<dir>/<slug>.<env>.key)"
php artisan env:decrypt --env=<env> --force
cp .env.<env> .env
```

`env:decrypt` reads the key from `LARAVEL_ENV_ENCRYPTION_KEY`, decrypts `.env.<env>.encrypted` back to
`.env.<env>`, and you copy that to the active `.env`.

## Usage

Provision UAT — encrypt `.env.uat`, install the key on the configured host, copy it to your clipboard:

```bash
php artisan secrets:provision uat
```

Production, overriding the host and key location for this run:

```bash
php artisan secrets:provision production --host=prod-box --dir=/etc/myapp --slug=myapp
```

Encrypt only, without touching any server (e.g. rotating the committed ciphertext locally):

```bash
php artisan secrets:provision uat --local
```

After a successful run: commit the updated `.env.<env>.encrypted`, and confirm the key landed in your
password manager (it is already on your clipboard).

### Options

| Option    | Default                              | Purpose                                             |
|-----------|--------------------------------------|-----------------------------------------------------|
| `env`     | *(required)*                         | Environment to encrypt, e.g. `uat`, `production`.   |
| `--host`  | `config('env-secrets.host')`         | ssh host alias of the box that stores the key.      |
| `--dir`   | `config('env-secrets.dir')`          | Directory on the box that holds the key files.      |
| `--slug`  | config, else app name                | Filename stem — key is `<slug>.<env>.key`.          |
| `--group` | `config('env-secrets.group')`        | Unix group allowed to read the key — see below.     |
| `--local` | off                                  | Encrypt locally only; skip installing on the box.   |

### Sharing a key with a team

By default the key lands `600`, owned by the user you ssh in as — so exactly **one person** can run
`secrets:edit` against that box. Everyone else gets
`Could not read the key at <host>:<path> — run \`secrets:provision\` first?`, which reads like a missing
key and is really a permission denial. The key is fetched with a plain `ssh <host> cat`; there is no
sudo fallback, deliberately.

To let a team share it, name a unix group. The directory becomes `750` and the key `640`, owned by
`<your user>:<group>`:

```bash
# once, on the box
sudo groupadd -f deployers && sudo usermod -aG deployers alice

php artisan secrets:provision production --group=deployers
```

Prefer setting `group` in the config over fixing the permissions by hand: **a key rotation re-runs the
install step**, and only a configured group survives it — a hand-applied `chgrp` is silently reverted to
owner-only the next time anyone rotates, locking the team out again with that same misleading error.

Group membership applies to *new* logins; an open ssh session keeps the groups it started with.

## Editing an env later

Once an env is provisioned, the key already lives on the box — so editing it is a two-step loop that
**reuses that key** instead of minting a new one:

```bash
php artisan secrets:edit production          # fetch key from box → writes plaintext .env.production
# ...edit .env.production...
php artisan secrets:reencrypt production --prune   # re-encrypt with the SAME key, verify, remove plaintext
```

`secrets:reencrypt` decrypts the fresh ciphertext in memory and asserts it matches your plaintext before
you commit, so a bad encrypt can never reach the repo. `--prune` deletes the plaintext after a verified
run. Then commit `.env.production.encrypted`.

> **Never re-run `php artisan env:encrypt` by hand to update an already-provisioned env.** That command
> reads the key **only** from `--key` — it ignores `LARAVEL_ENV_ENCRYPTION_KEY` — and, run
> non-interactively, silently mints a throwaway random key. The result is ciphertext your box can no
> longer decrypt ("The MAC is invalid" at deploy). `secrets:reencrypt` exists precisely to prevent this:
> it always feeds `env:encrypt` the real box key.

## Inspecting an env

```bash
php artisan secrets:status production            # provisioned? does the box key decrypt the committed file?
php artisan secrets:show production              # list variable NAMES with masked values (no plaintext on disk)
php artisan secrets:show production DB_PASSWORD   # print ONE value (explicit per-value opt-in)
```

Both take `--remote` to read the **live deployed `.env`** on the server instead of the committed
ciphertext — the source of truth for what the app is actually running. Set `app_path` (or `--path`) first:

```bash
php artisan secrets:show production DB_HOST --remote
php artisan secrets:status production --remote
```

## Security notes

- The key is minted with `random_bytes` (CSPRNG) and passed to `env:encrypt` via `--key`, so `env:encrypt`
  never generates a key of its own. It *does* echo back the key it was handed — its last line is
  `twoColumnDetail('Key', ...)` — so it is invoked with `callSilently()` and the console only ever sees
  this command's own summary.
- The key is streamed to the server over **ssh stdin** and to the clipboard over **pbcopy stdin** — never
  as a shell argument, so it stays out of `ps`, shell history, and CI logs.
- On the box the key file is created `600`, owned by the connecting ssh user, in a `700` directory.
- `env`, `slug`, and `dir` are validated (`[a-z0-9-]` / absolute path) before any process runs.

## Testing

```bash
composer test
```

## Credits

- [Phattarachai](https://github.com/phatchai)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
