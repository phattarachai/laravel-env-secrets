---
name: env-secrets
description: Use this skill whenever you need to read or change an encrypted environment file (`.env.<env>.encrypted`) in a project using `phattarachai/laravel-env-secrets` — production, uat, or any non-local env. It is the ONLY correct way to touch these files: NEVER run `php artisan env:encrypt` by hand, because that command reads the key only from `--key` (it ignores `LARAVEL_ENV_ENCRYPTION_KEY`) and, run non-interactively, silently mints a throwaway random key — producing ciphertext the deploy box can no longer decrypt. This package's `secrets:*` commands fetch the real key from the deploy box over ssh and always pass it through, so the key can never drift and never lands in argv, shell history, or a CI log. Covers editing an env (`secrets:edit` → `secrets:reencrypt`), first-time setup (`secrets:provision`), health-checking (`secrets:status`), and peeking at values without decrypting to disk (`secrets:show`), plus the `--remote` mode that reads the live deployed `.env`. Triggers on: "edit the production env", "add a key to .env.production", "re-encrypt the env", ".env.production.encrypted", "env:encrypt", "MAC is invalid", "decryption failed after deploy", "what is DB_PASSWORD on production", "rotate an env value", or any request to change committed encrypted env files.
version: 2026.09.09.1
---

# Env Secrets

`phattarachai/laravel-env-secrets` encrypts `.env.<env>` with Laravel's `env:encrypt`, but keeps the
decryption key on the **deploy box** (at `<dir>/<slug>.<env>.key`, `600`, owned by the ssh user) — never
in the repo, never a GitHub secret. The `secrets:*` commands are the interface: they fetch that key over
ssh and drive `env:encrypt` / `env:decrypt` in-process, so the key stays in memory only and out of argv,
`ps`, shell history, and CI logs.

**Never hand-run `php artisan env:encrypt` on these projects.** See [`reference.md`](reference.md) §
"The env:encrypt footgun" for exactly why — it is the bug this package exists to prevent.

## The edit loop (the headline workflow)

To change any encrypted env — add a key, rotate a value — it is always two commands:

```bash
php artisan secrets:edit production      # fetch key from box → writes plaintext .env.production
# ...edit .env.production...
php artisan secrets:reencrypt production --prune   # re-encrypt with the SAME key, verify, delete plaintext
```

- `secrets:edit <env>` decrypts `.env.<env>.encrypted` → `.env.<env>` using the key on the box.
- Edit `.env.<env>` with a normal editor.
- `secrets:reencrypt <env>` re-encrypts with the box key, then **verifies the round-trip** (decrypts the
  fresh ciphertext in memory and checks it matches your plaintext) before you commit. `--prune` deletes the
  plaintext after a verified encrypt. Then **commit `.env.<env>.encrypted`**.

Because the key is fetched from the box (not minted, not read from an env var), the ciphertext always
decrypts with the key the deploy will use. That is the whole point.

## First-time setup

For an env that has never been encrypted (no key on the box yet):

```bash
php artisan secrets:provision production   # mint a key, encrypt, install the key on the box, copy to clipboard
```

Run it from the machine holding the plaintext `.env.production`. It prints nothing secret; paste the
clipboard key into your password manager and commit `.env.production.encrypted`. Use `--local` to encrypt
without installing the key on a server.

## Inspecting without decrypting to disk

```bash
php artisan secrets:status production            # is it provisioned + does the box key decrypt it?
php artisan secrets:show production              # list variable NAMES with masked values
php artisan secrets:show production DB_PASSWORD   # print ONE value (explicit opt-in)
```

`secrets:show` decrypts in memory and never writes plaintext. With no name it masks every value; naming a
variable reveals just that one.

## --remote: read what is actually running

`secrets:status` and `secrets:show` take `--remote`, which reads the **live deployed `.env`** on the server
(over `sudo -n cat`) instead of the committed ciphertext — the source of truth for "what is production
actually set to right now". It needs the deployed app directory: set `env-secrets.app_path`
(`ENV_SECRETS_APP_PATH`) or pass `--path`.

```bash
php artisan secrets:show production DB_HOST --remote   # the value the running app sees
php artisan secrets:status production --remote          # the live .env is present + var count
```

## Configuration

`config/env-secrets.php` (all overridable per run):

- `host` (`--host`) — ssh alias of the box that stores the key.
- `dir` (`--dir`) — directory on the box holding key files.
- `slug` (`--slug`) — filename stem; the key is `<slug>.<env>.key`. Defaults to the app-name slug.
- `app_path` (`--path`) — deployed app directory, for `--remote` reads.

## When something is wrong

- **"The MAC is invalid" / deploy fails at `env:decrypt`** — the committed ciphertext was encrypted with a
  different key than the box holds, almost always from a hand-run `env:encrypt`. Fix: `secrets:edit <env>`
  is impossible (box key won't decrypt the bad file), so recover the previous good `.encrypted` from git,
  then redo the change through `secrets:edit` + `secrets:reencrypt`. Details in `reference.md`.
- **`secrets:status <env>` returns MISSING on "Decrypts"** — the box key does not match the committed file.
  Same cause as above.
- **`Could not read the key ...`** — the key isn't on the box for that env; run `secrets:provision <env>`.
