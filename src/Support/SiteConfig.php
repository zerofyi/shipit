<?php

namespace Zerofyi\ShipIt\Support;

/**
 * Resolves the effective configuration for one target site and validates it.
 *
 * Resolution order for every value: named profile (config/shipit.php
 * "sites" array) first, then the classic .env variables. This keeps every
 * existing single-site .env working unchanged (backward compatible) while
 * enabling multiple websites on one Hostinger account via profiles.
 */
class SiteConfig
{
    /** Map of profile keys to the .env variable they override. */
    public const PROFILE_KEYS = [
        'repo_url' => 'GITHUB_REPO_URL',
        'github_token' => 'GITHUB_API_TOKEN',
        'ssh_host' => 'HOSTINGER_SSH_HOST',
        'ssh_user' => 'HOSTINGER_SSH_USERNAME',
        'ssh_port' => 'HOSTINGER_SSH_PORT',
        'site_dir' => 'HOSTINGER_SITE_DIR',
    ];

    /**
     * @return array{site:string, repo_url:?string, github_token:?string,
     *               ssh_host:?string, ssh_user:?string, ssh_port:int, site_dir:?string}
     */
    public static function resolve(?string $profile = null): array
    {
        $overrides = [];
        if ($profile !== null && $profile !== '' && $profile !== 'default') {
            $sites = (array) config('shipit.sites', []);
            $overrides = (array) ($sites[$profile] ?? []);
        }

        $pick = function (string $key) use ($overrides) {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null && $overrides[$key] !== '') {
                return $overrides[$key];
            }
            return env(self::PROFILE_KEYS[$key]);
        };

        $port = $pick('ssh_port');

        return [
            'site' => $profile ?: 'default',
            'repo_url' => $pick('repo_url') ? trim((string) $pick('repo_url')) : null,
            'github_token' => $pick('github_token') ? trim((string) $pick('github_token')) : null,
            'ssh_host' => $pick('ssh_host') ? trim((string) $pick('ssh_host')) : null,
            'ssh_user' => $pick('ssh_user') ? trim((string) $pick('ssh_user')) : null,
            'ssh_port' => ($port === null || $port === '') ? 22 : (int) $port,
            'site_dir' => $pick('site_dir') ? trim((string) $pick('site_dir')) : null,
        ];
    }

    /** Does a named profile exist in config? ('default' always exists.) */
    public static function profileExists(?string $profile): bool
    {
        if ($profile === null || $profile === '' || $profile === 'default') {
            return true;
        }
        $sites = (array) config('shipit.sites', []);
        return array_key_exists($profile, $sites);
    }

    /** @return string[] Names of all configured profiles. */
    public static function profileNames(): array
    {
        return array_keys((array) config('shipit.sites', []));
    }

    /**
     * Validate a resolved config for a GitHub-only operation.
     *
     * The API token is OPTIONAL here — and always was, until v2.1.0
     * wrongly made it mandatory. push:github pushes with the laptop's
     * own git credentials, and push:hostinger only needs the token for
     * automatic deploy-key injection on private repos: public repos
     * need no auth at all, and private repos fall back to a manual
     * deploy-key card when no token is set. A token that IS present is
     * still checked for truncation, since a cut-off token breaks the
     * trust layer in confusing ways.
     *
     * @return string[] Human-readable errors; empty when valid.
     */
    public static function validateGithub(array $cfg): array
    {
        $errors = [];

        if (empty($cfg['repo_url'])) {
            $errors[] = 'GITHUB_REPO_URL is missing.';
        } elseif (!Urls::isValidRepoUrl($cfg['repo_url'])) {
            $errors[] = 'GITHUB_REPO_URL is not a valid repository URL: "' . $cfg['repo_url'] . '" (expected an https:// or git@ URL).';
        }

        if (!empty($cfg['github_token']) && strlen((string) $cfg['github_token']) < 20) {
            $errors[] = 'GITHUB_API_TOKEN looks suspiciously short (possible truncation).';
        }

        return $errors;
    }

    /**
     * Validate a resolved config for a server operation (deploy, backup,
     * rollback, doctor...). Includes the GitHub checks when $needGithub.
     *
     * @return string[]
     */
    public static function validateServer(array $cfg, bool $needGithub = true): array
    {
        $errors = $needGithub ? self::validateGithub($cfg) : [];

        if (empty($cfg['ssh_host'])) {
            $errors[] = 'HOSTINGER_SSH_HOST is missing.';
        } elseif (!preg_match('/^[a-zA-Z0-9.\-]+$/', (string) $cfg['ssh_host'])) {
            $errors[] = 'HOSTINGER_SSH_HOST contains invalid characters: "' . $cfg['ssh_host'] . '"';
        }

        if (empty($cfg['ssh_user'])) {
            $errors[] = 'HOSTINGER_SSH_USERNAME is missing.';
        } elseif (!preg_match('/^[a-zA-Z0-9._\-]+$/', (string) $cfg['ssh_user'])) {
            $errors[] = 'HOSTINGER_SSH_USERNAME contains invalid characters: "' . $cfg['ssh_user'] . '"';
        }

        $port = (int) ($cfg['ssh_port'] ?? 22);
        if ($port < 1 || $port > 65535) {
            $errors[] = 'HOSTINGER_SSH_PORT is out of range: ' . $port;
        }

        if (empty($cfg['site_dir'])) {
            $errors[] = 'HOSTINGER_SITE_DIR is missing.';
        } elseif (!preg_match('/^[a-zA-Z0-9.\-_]+$/', (string) $cfg['site_dir']) || str_contains((string) $cfg['site_dir'], '..')) {
            // site_dir is interpolated into remote paths and shell commands;
            // anything beyond a plain domain-style name is refused outright.
            $errors[] = 'HOSTINGER_SITE_DIR looks suspicious: "' . $cfg['site_dir'] . '" (expected a plain domain like example.com).';
        }

        return $errors;
    }
}
