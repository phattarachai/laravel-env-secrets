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
     * Absolute path to the deployed application directory on the box. Used by
     * `secrets:status --remote` and `secrets:show --remote` to read the live
     * .env that is actually running there. Leave null to disable remote reads;
     * override per run with --path.
     */
    'app_path' => env('ENV_SECRETS_APP_PATH', null),
];
