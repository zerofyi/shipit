<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pre-Deploy Backups
    |--------------------------------------------------------------------------
    |
    | Before every deployment, ShipIt snapshots the production state of the
    | target site on the server (database dump, user uploads, built assets,
    | .env copy and a manifest) so any deploy can be rolled back. Snapshots
    | are stored per site OUTSIDE the web root:
    |
    |   /home/<ssh-user>/shipit-backups/<site-dir>/
    |
    | Set "enabled" to false (or pass --skip-backup) for applications that
    | do not need backups. The deploy journal always records whether a
    | backup was taken or skipped, so rollback can warn you honestly when
    | the nearest database restore point is older than a given deploy.
    |
    */

    'backup' => [
        'enabled' => env('SHIPIT_BACKUP', true),
        'retention' => (int) env('SHIPIT_BACKUP_RETENTION', 3),
        'download' => env('SHIPIT_BACKUP_DOWNLOAD', false),
        'download_path' => env('SHIPIT_BACKUP_DOWNLOAD_PATH', storage_path('app/shipit-backups')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-Deploy Health Check
    |--------------------------------------------------------------------------
    |
    | After a deployment finishes, ShipIt requests the site URL (and any
    | extra URLs listed here, relative to the site root) and expects a
    | successful HTTP response. When the check fails, ShipIt immediately
    | offers a one-command rollback to the previous known-good deploy.
    |
    */

    'health_check' => [
        'enabled' => env('SHIPIT_HEALTH_CHECK', true),
        'urls' => [],
        'timeout' => (int) env('SHIPIT_HEALTH_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deploy Hooks
    |--------------------------------------------------------------------------
    |
    | Extra shell commands executed ON THE SERVER around the deployment
    | pipeline. "before" hooks run after the backup and before the code
    | sync; a failing "before" hook aborts the deployment. "after" hooks
    | run at the very end; their failures are reported as warnings only.
    | Commands run inside the site directory.
    |
    */

    'hooks' => [
        'before' => [],
        'after' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Site Profiles (Multiple Websites on One Hostinger Account)
    |--------------------------------------------------------------------------
    |
    | A Hostinger account usually hosts several websites. Define a named
    | profile per website and deploy with:  php artisan push:hostinger --site=shop
    |
    | Every key is optional and falls back to the matching .env value:
    |   repo_url    -> GITHUB_REPO_URL
    |   github_token-> GITHUB_API_TOKEN
    |   ssh_host    -> HOSTINGER_SSH_HOST
    |   ssh_user    -> HOSTINGER_SSH_USERNAME
    |   ssh_port    -> HOSTINGER_SSH_PORT
    |   site_dir    -> HOSTINGER_SITE_DIR
    |
    | Example:
    |   'sites' => [
    |       'shop' => [
    |           'site_dir' => 'shop.com',
    |           'repo_url' => 'https://github.com/you/shop.git',
    |       ],
    |       'blog' => [
    |           'site_dir' => 'blog.com',
    |           'repo_url' => 'https://github.com/you/blog.git',
    |       ],
    |   ],
    |
    */

    'sites' => [],

];
