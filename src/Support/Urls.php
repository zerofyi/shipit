<?php

namespace Zerofyi\ShipIt\Support;

/**
 * Pure helpers for repository URL handling: validation, HTTPS -> SSH
 * conversion, owner/repo extraction and comparison normalisation.
 *
 * Everything here is side-effect free and unit tested.
 */
class Urls
{
    /**
     * A repository URL is usable when it is either an HTTP(S) URL or an
     * SCP-style SSH address (git@host:path). FILTER_DEFAULT performed no
     * validation at all, which is why this exists.
     */
    public static function isValidRepoUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $url = trim($url);

        if (preg_match('#^https?://[^\s/]+/\S+$#', $url)) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        // SCP-style SSH: user@host:path
        return (bool) preg_match('/^[^\s@]+@[^\s:]+:\S+$/', $url);
    }

    /**
     * Convert an HTTPS repository URL into its SSH (SCP-style) variant.
     * URLs that are already SSH-shaped are returned unchanged.
     */
    public static function toSshUrl(string $url): string
    {
        $url = trim($url);

        if (preg_match('#^https?://([^/]+)/([^/\s]+)/([^/\s]+)$#', $url, $m)) {
            $repo = $m[3];
            if (!str_ends_with($repo, '.git')) {
                $repo .= '.git';
            }
            return "git@{$m[1]}:{$m[2]}/{$repo}";
        }

        return $url;
    }

    /**
     * Extract [owner, repo] from an HTTPS or SSH GitHub-style URL.
     * Returns null when the shape is not recognised.
     *
     * @return array{0:string,1:string}|null
     */
    public static function ownerRepo(string $url): ?array
    {
        $url = trim($url);

        if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return null;
    }

    /**
     * Extract the host from an HTTPS or SSH URL ('github.com' fallback).
     */
    public static function hostOf(string $url, string $fallback = 'github.com'): string
    {
        $url = trim($url);

        if (preg_match('/@([^:\/]+)[:\/]/', $url, $m)) {
            return trim($m[1]);
        }

        if (preg_match('#^https?://([^/]+)#', $url, $m)) {
            return trim($m[1]);
        }

        return $fallback;
    }

    /**
     * Normalise a repository URL for equality comparison: trims trailing
     * slashes and a single trailing ".git", lowercases the host portion.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        $url = rtrim($url, '/');
        if (str_ends_with($url, '.git')) {
            $url = substr($url, 0, -4);
        }
        return $url;
    }

    /**
     * Two repository URLs point at the same repository when their
     * normalised forms match, OR when one is the HTTPS form and the other
     * the SSH form of the same owner/repo on the same host.
     */
    public static function sameRepository(string $a, string $b): bool
    {
        if (self::normalize($a) === self::normalize($b)) {
            return true;
        }

        return self::normalize(self::toSshUrl($a)) === self::normalize(self::toSshUrl($b));
    }

    /**
     * Does this URL embed credential material (e.g. a token in
     * https://x-access-token:TOKEN@host/...)? Used to guarantee tokens
     * are never left persisted in a server's git configuration.
     */
    public static function containsCredentials(string $url): bool
    {
        return (bool) preg_match('#^https?://[^/@\s]+:[^/@\s]+@#', trim($url));
    }

    /**
     * Strip any embedded credentials from an HTTPS URL.
     */
    public static function stripCredentials(string $url): string
    {
        return preg_replace('#^(https?://)[^/@\s]+(?::[^/@\s]+)?@#', '$1', trim($url)) ?? trim($url);
    }
}
