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
];
