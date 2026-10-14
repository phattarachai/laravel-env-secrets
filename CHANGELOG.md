# Changelog

All notable changes to `phattarachai/laravel-env-secrets` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/laravel-env-secrets/releases) — that page is the authoritative log.

This file records anything released before that automation landed, plus upgrade notes for releases that need them.

## v1.6.0 — 2026-10-14

### Changed

- `secrets:status --remote` and `secrets:show --remote` follow the `sudo` setting. Before, they always
  ran `ssh <host> sudo -n cat <app_path>/.env`, so on a box where the ssh user owns the app directory and
  has no passwordless sudo (a Mac mini) they failed with `sudo: a password is required`, though a plain
  `cat` would have worked. Now `auto` (the default) tries a plain `cat` first and uses `sudo -n cat` only
  when that is refused with `Permission denied`. `never` uses a plain `cat` only, and `always` keeps the
  old `sudo -n cat`.

### Added

- `--sudo` option on `secrets:status` and `secrets:show`.

### Upgrading

Nothing to do. A box that needed `sudo -n cat` still gets it under `auto`, one plain `cat` later. To skip
that first attempt, set `sudo` to `always` for that env.

## v1.5.0 — 2026-10-11

### Fixed

- `secrets:provision` no longer requires sudo. Before, it ran `sudo install …; sudo tee …` on every box,
  so a key dir in the ssh user's home on a box with no passwordless sudo (a Mac mini) failed with
  `sudo: a terminal is required to read the password`. Now it uses sudo only when the ssh user cannot own
  the key dir, and then only as `sudo -n`.
- A failed install no longer loses the key. The new key is on the clipboard with a `pbpaste | ssh …`
  command to install it by hand. Without a clipboard, `.env.<env>.encrypted` is restored. On the box, the
  key is written to a `.tmp` beside the old one and renamed over it, so a failure leaves the current key
  intact.

### Added

- `sudo` setting: `auto` (default), `always` / `true`, or `never` / `false`. Set it in config, per env in
  `environments`, or per run with `--sudo`.

### Upgrading

Nothing to do. The published config does not need the new key; it defaults to `auto`.

## v1.4.0 — 2026-09-30

### Added

- Per-environment box settings: `'environments' => ['staging' => ['host' => 'staging-box']]` in
  `config/env-secrets.php`. Any of `host`, `dir`, `slug`, `group` and `app_path` can be set per env, and
  every command honours it. Resolution order: CLI option → `environments.<env>` → top-level key → default.
- Every command opens by printing the box and key path it resolved (`Box  <host>:<path>`, on stderr), and
  `secrets:status` prints the host and key path, with where each came from, before it reads the key.

### Upgrading

Nothing to do unless an env lives on a different box. If one does, add it to `environments` in your
published config (re-publish with `--force`, or copy the block from the package config) and drop the
`--host` you have been passing by hand.
