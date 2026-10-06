<?php

declare(strict_types=1);

namespace ClipHunter\Security;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\PlatformRegistry;

/**
 * Turns untrusted user input into a {@see ValidatedUrl} or rejects it.
 *
 * Layers (see docs/ARCHITECTURE.md §8.2):
 *  1. syntax: http(s) only, no userinfo, default ports only, no IP literals, IDN to punycode;
 *  2. host allowlist of supported platforms;
 *  3. DNS: every resolved address must be public.
 * The URL is then rebuilt from its parsed parts, so yt-dlp never sees the raw input.
 */
final readonly class UrlValidator
{
    public const MAX_LENGTH = 2048;

    public function __construct(
        private PlatformRegistry $platforms,
        private HostResolver $resolver,
    ) {
    }

    /**
     * @throws ApiException INVALID_URL or UNSUPPORTED_SOURCE
     */
    public function validate(string $input): ValidatedUrl
    {
        $raw = trim($input);

        if ($raw === '' || strlen($raw) > self::MAX_LENGTH) {
            throw self::invalid('empty_or_too_long');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw self::invalid('invalid_utf8');
        }
        // Whitespace, control characters and backslashes are where URL parsers disagree.
        if (preg_match('~[\x00-\x20\x7F\\\\]~', $raw) === 1 || preg_match('~[\x{80}-\x{9F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]~u', $raw) === 1) {
            throw self::invalid('forbidden_characters');
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $raw) !== 1) {
            // "youtube.com/watch?v=..." — users often paste links without a scheme.
            $raw = 'https://' . $raw;
        }

        $parts = parse_url($raw);
        if ($parts === false) {
            throw self::invalid('unparseable');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw self::invalid('scheme_not_allowed');
        }
        if (!preg_match('~^https?://~i', $raw)) {
            throw self::invalid('missing_authority');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::invalid('userinfo');
        }
        if (isset($parts['port']) && !in_array($parts['port'], [80, 443], true)) {
            throw self::invalid('port_not_allowed');
        }

        $host = $this->normalizeHost($parts['host'] ?? '');

        $platform = $this->platforms->forHost($host);
        if ($platform === null) {
            throw new ApiException(ErrorCode::UnsupportedSource, 'host_not_allowlisted');
        }

        $this->assertResolvesToPublicAddresses($host);

        $path = $parts['path'] ?? '';
        if ($path !== '' && !str_starts_with($path, '/')) {
            throw self::invalid('relative_path');
        }
        $url = 'https://' . $host . ($path === '' ? '/' : $path);
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }
        // The fragment is intentionally dropped: it is never sent to servers.

        return new ValidatedUrl($url, $host, $platform);
    }

    /**
     * Checks a URL our server itself requests from a fixed third-party service (not user input,
     * but still never trusted blindly): https, default port, no userinfo, a host from the given
     * allowlist (or its subdomain) that resolves to public addresses only.
     *
     * @param list<string> $allowedHosts lowercase hosts
     *
     * @throws ApiException UNSUPPORTED_SOURCE when the URL is not acceptable
     */
    public function validateServiceUrl(string $url, array $allowedHosts): string
    {
        $parts = strlen($url) <= self::MAX_LENGTH ? parse_url($url) : false;
        if (
            $parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443) || preg_match('~[\x00-\x20\x7F\\\\]~', $url) === 1
        ) {
            throw new ApiException(ErrorCode::UnsupportedSource, 'service_url_rejected');
        }
        $host = $this->normalizeHost($parts['host'] ?? '');
        $allowed = false;
        foreach ($allowedHosts as $domain) {
            $allowed = $allowed || $host === $domain || str_ends_with($host, '.' . $domain);
        }
        if (!$allowed) {
            throw new ApiException(ErrorCode::UnsupportedSource, 'service_host_not_allowlisted');
        }
        $this->assertResolvesToPublicAddresses($host);

        return $url;
    }

    private function normalizeHost(string $host): string
    {
        if ($host === '' || str_starts_with($host, '[')) {
            throw self::invalid('missing_host_or_ip_literal');
        }

        $host = rtrim($host, '.');

        if (preg_match('~[^\x20-\x7E]~', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw self::invalid('bad_idn');
            }
            $host = $ascii;
        }

        $host = strtolower($host);

        if (strlen($host) > 253 || preg_match('~^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$~', $host) !== 1) {
            throw self::invalid('bad_hostname');
        }

        // Any IP literal, including forms resolvers accept but filter_var does not
        // (2130706433, 0x7f.1, 127.1, 017700000001).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('~^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$~', $host) === 1) {
            throw self::invalid('ip_literal');
        }

        return $host;
    }

    private function assertResolvesToPublicAddresses(string $host): void
    {
        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw self::invalid('dns_unresolvable');
        }
        foreach ($ips as $ip) {
            if (!IpRangeChecker::isPublic($ip)) {
                throw new ApiException(ErrorCode::UnsupportedSource, 'dns_resolves_to_non_public_address');
            }
        }
    }

    private static function invalid(string $reason): ApiException
    {
        return new ApiException(ErrorCode::InvalidUrl, $reason);
    }
}
