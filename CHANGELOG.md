# Changelog

All notable changes to `phattarachai/laravel-env-secrets` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/laravel-env-secrets/releases) — that page is the authoritative log.

This file records anything released before that automation landed, plus upgrade notes for releases that need them.

## v1.4.0 — 2026-09-30

### Added

- Per-environment box settings: `'environments' => ['staging' => ['host' => 'necta-v2dev']]` in
  `config/env-secrets.php`. Any of `host`, `dir`, `slug`, `group` and `app_path` can be set per env, and
  every command honours it. Resolution order: CLI option → `environments.<env>` → top-level key → default.
- Every command opens by printing the box and key path it resolved (`Box  <host>:<path>`, on stderr), and
  `secrets:status` prints the host and key path, with where each came from, before it reads the key.

### Upgrading

Nothing to do unless an env lives on a different box. If one does, add it to `environments` in your
published config (re-publish with `--force`, or copy the block from the package config) and drop the
`--host` you have been passing by hand.
