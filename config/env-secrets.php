<?php

return [
    /*
     * SSH host alias (from ~/.ssh/config) of the deploy box that stores the
     * decryption key. Overridable per run with --host.
     */
    'host' => env('ENV_SECRETS_HOST', 'necta'),

    /*
     * Absolute directory on the box that holds the key files. The command
     * creates it (700, owned by the ssh user) if it is missing. Overridable
     * per run with --dir.
     */
    'dir' => env('ENV_SECRETS_DIR', '/etc/nectapharma'),

    /*
     * Filename stem — the key is written as <slug>.<env>.key. Leave null to
     * derive it from the app name (Str::slug(config('app.name'))), falling back
     * to the application directory name. Overridable per run with --slug.
     */
    'slug' => env('ENV_SECRETS_SLUG', null),

    /*
     * Unix group granted read access to the key file, so more than one teammate
     * can run secrets:edit / reencrypt / status against the box. Leave null and
     * the key stays 600 owned by the ssh user — i.e. exactly one person can use
     * these commands. Set it here rather than fixing permissions by hand: a key
     * rotation re-runs the install step, and only a configured group survives it.
     * Overridable per run with --group.
     */
    'group' => env('ENV_SECRETS_GROUP', null),

    /*
     * How `secrets:provision` may use sudo when it installs the key:
     *
     *   'auto'   — only when it has to: the key dir is created and written as the ssh user whenever
     *              that user owns it or can create it (a dir under their home), and `sudo -n` is
     *              reached for only when it cannot (a dir under /etc) or for a group the user is
     *              not in. The default.
     *   'always' — `sudo -n` for the dir and the group change. (true works too.)
     *   'never'  — no sudo at all, e.g. a Mac mini with no passwordless sudo. (false works too.)
     *
     * sudo always runs as `sudo -n`: over a non-interactive ssh a password prompt can never be
     * answered, so it fails fast instead. Overridable per run with --sudo.
     */
    'sudo' => env('ENV_SECRETS_SUDO', 'auto'),

    /*
     * Absolute path to the deployed application directory on the box. Used by
     * `secrets:status --remote` and `secrets:show --remote` to read the live
     * .env that is actually running there. Leave null to disable remote reads;
     * override per run with --path.
     */
    'app_path' => env('ENV_SECRETS_APP_PATH', null),

    /*
     * Per-environment overrides, for a project whose envs do not all live on one box. Any of
     * host / dir / slug / group / sudo / app_path may be set per env; whatever an env leaves out falls
     * back to the top-level value above. The order, most specific first: the CLI option
     * (--host, --dir, --slug, --group, --sudo, --path), then this map, then the top-level key, then the
     * built-in default.
     *
     * Set it here rather than remembering --host: a forgotten flag sends the command to the wrong
     * box. Every command prints the host and key path it resolved before it does anything.
     *
     *   'environments' => [
     *       'staging' => ['host' => 'app-staging', 'app_path' => '/var/www/staging'],
     *       'production' => ['app_path' => '/var/www/production'],
     *       'mini' => ['host' => 'pc-mini', 'dir' => '/Users/deploy/.config/env-secrets', 'sudo' => 'never'],
     *   ],
     */
    'environments' => [],

    /*
     * `secrets:merge` appends keys a local .env is missing. These patterns (Str::is globs) are
     * NEVER written by it — not even with --replace --force. They are the seatbelt for the day
     * someone points a merge at a deploy environment: a developer's own APP_KEY, database
     * credentials and driver choices stay whatever their machine already had. Keys that are the
     * same everywhere — third-party API tokens — are exactly what is left to flow.
     */
    'merge' => [
        'protected' => [
            'APP_KEY',
            'APP_ENV',
            'APP_URL',
            'APP_DEBUG',
            'DB_*',
            'REDIS_*',
            '*_DRIVER',
        ],
    ],

];
