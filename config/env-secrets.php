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
     * Absolute path to the deployed application directory on the box. Used by
     * `secrets:status --remote` and `secrets:show --remote` to read the live
     * .env that is actually running there. Leave null to disable remote reads;
     * override per run with --path.
     */
    'app_path' => env('ENV_SECRETS_APP_PATH', null),
];
