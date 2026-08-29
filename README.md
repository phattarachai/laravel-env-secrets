# Laravel Env Secrets

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/laravel-env-secrets.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-env-secrets)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-env-secrets/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/laravel-env-secrets/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-env-secrets/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/laravel-env-secrets/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/laravel-env-secrets/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/laravel-env-secrets)
![Laravel Version](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/laravel-env-secrets.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-env-secrets)

One artisan command — `secrets:provision` — to run the encrypted-`.env` deploy pattern end to end:
mint an encryption key, encrypt `.env.<env>` with Laravel's own `env:encrypt`, and install the key on
your deploy box over ssh. The key is generated with a CSPRNG and **never** touches stdout, your shell
history, or a process argument — it reaches the server over ssh stdin and your clipboard via `pbcopy`.

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
    'host' => env('ENV_SECRETS_HOST', 'necta'),          // ssh host alias of the deploy box
    'dir'  => env('ENV_SECRETS_DIR', '/etc/nectapharma'), // where key files live on the box
    'slug' => env('ENV_SECRETS_SLUG', null),              // key filename stem; null → app name
];
```

Every value is overridable per run with `--host`, `--dir`, `--slug`. When an option is omitted the command
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
| `--local` | off                                  | Encrypt locally only; skip installing on the box.   |

## Security notes

- The key is minted with `random_bytes` (CSPRNG) and passed to `env:encrypt` via `--key`, so `env:encrypt`
  never generates or prints its own key.
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
