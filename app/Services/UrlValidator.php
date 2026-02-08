<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * URL validation and security enforcement service.
 *
 * Validates URLs against blocked domains, non-routable IP ranges,
 * scheme and port allowlists, and DNS rebinding attacks. Resolves hostnames
 * to IP addresses with IPv4/IPv6 support for safe proxying.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class UrlValidator
{
    /**
     * Create a new URL validator instance.
     *
     * @param array<int, string>    $blockedDomains List of domain names to block
     * @param array<int, string>    $blockedIps     List of IP addresses to block
     * @param array<int, string>    $allowedSchemes List of allowed URL schemes (e.g. 'http', 'https')
     * @param array<int, int>       $allowedPorts   List of allowed destination ports (e.g. 80, 443)
     * @param array<string, string> $blockedRanges  CIDR ranges to block, keyed by range with a label as value
     */
    public function __construct(
        private array $blockedDomains,
        private array $blockedIps,
        private array $allowedSchemes,
        private array $allowedPorts = [80, 443, 8080, 8443],
        private array $blockedRanges = self::DEFAULT_BLOCKED_RANGES,
    ) {}

    /**
     * Ranges blocked when none are given: everything not globally routable,
     * plus the IPv6 transition prefixes that carry an IPv4 address.
     *
     * @var array<string, string>
     */
    public const DEFAULT_BLOCKED_RANGES = [
        '0.0.0.0/8' => 'this-network range',
        '10.0.0.0/8' => 'private range (RFC1918)',
        '100.64.0.0/10' => 'shared address space (CGNAT, RFC6598)',
        '127.0.0.0/8' => 'loopback range',
        '169.254.0.0/16' => 'link-local range',
        '172.16.0.0/12' => 'private range (RFC1918)',
        '192.0.0.0/24' => 'IETF protocol assignments range',
        '192.0.2.0/24' => 'documentation range (TEST-NET-1)',
        '192.88.99.0/24' => '6to4 relay anycast range',
        '192.168.0.0/16' => 'private range (RFC1918)',
        '198.18.0.0/15' => 'benchmarking range',
        '198.51.100.0/24' => 'documentation range (TEST-NET-2)',
        '203.0.113.0/24' => 'documentation range (TEST-NET-3)',
        '224.0.0.0/4' => 'multicast range',
        '240.0.0.0/4' => 'reserved range',
        '::/128' => 'unspecified address',
        '::1/128' => 'loopback address',
        '::/96' => 'IPv4-compatible range',
        '::ffff:0:0/96' => 'IPv4-mapped range',
        '64:ff9b::/96' => 'NAT64 range',
        '64:ff9b:1::/48' => 'local-use NAT64 range',
        '100::/64' => 'discard-only range',
        '2001::/32' => 'Teredo range',
        '2001:db8::/32' => 'documentation range',
        '2002::/16' => '6to4 range',
        'fc00::/7' => 'unique local range',
        'fe80::/10' => 'link-local range',
        'fec0::/10' => 'site-local range',
        'ff00::/8' => 'multicast range',
    ];

    /**
     * Resolve hostname to IP and validate it's not private/blocked.
     *
     * Returns a detailed validation result with reason if blocked.
     * This prevents DNS rebinding attacks by resolving once and reusing.
     *
     * @param string $url The URL whose host should be resolved and validated
     * @return array{valid: bool, ip?: string, reason?: string, details?: array<string, mixed>} Validation result
     */
    public function resolveAndValidateHost(string $url): array
    {
        $parsed = parse_url($url);

        if (!isset($parsed['host']) || empty($parsed['host'])) {
            return [
                'valid' => false,
                'reason' => 'Missing or empty host',
                'details' => [],
            ];
        }

        $host = strtolower($parsed['host']);

        // Strip IPv6 brackets (parse_url keeps them: [::1] → ::1)
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // Check for blocked domains first
        $blockedDomain = $this->getBlockedDomainMatch($host);
        if ($blockedDomain !== null) {
            return [
                'valid' => false,
                'reason' => 'Domain is blacklisted',
                'details' => [
                    'host' => $host,
                    'matched_rule' => $blockedDomain,
                ],
            ];
        }

        // If host is already an IP, validate it directly
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $blockReason = $this->getIpBlockReason($host);
            if ($blockReason !== null) {
                return [
                    'valid' => false,
                    'reason' => $blockReason,
                    'details' => ['ip' => $host],
                ];
            }
            return ['valid' => true, 'ip' => $host];
        }

        // Resolve hostname to IP (supports both IPv4 and IPv6)
        $resolved = $this->resolveHostname($host);

        if ($resolved === null) {
            return [
                'valid' => false,
                'reason' => 'DNS resolution failed',
                'details' => ['host' => $host],
            ];
        }

        // Validate the resolved IP
        $blockReason = $this->getIpBlockReason($resolved['ip']);
        if ($blockReason !== null) {
            return [
                'valid' => false,
                'reason' => $blockReason,
                'details' => [
                    'host' => $host,
                    'resolved_ip' => $resolved['ip'],
                    'ip_type' => $resolved['type'],
                ],
            ];
        }

        return ['valid' => true, 'ip' => $resolved['ip']];
    }

    /**
     * Resolve hostname to IP address (supports IPv4 and IPv6).
     *
     * Prefers IPv4 for compatibility but falls back to IPv6 if only IPv6 is available.
     * Uses dns_get_record() for both A and AAAA records, with gethostbyname() as
     * a last-resort fallback for IPv4.
     *
     * @param string $host The hostname to resolve
     * @return array{ip: string, type: string}|null Resolved IP info or null on failure
     */
    private function resolveHostname(string $host): ?array
    {
        // Try IPv4 first (DNS_A)
        $records = @dns_get_record($host, DNS_A);
        if ($records !== false && !empty($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    return ['ip' => $record['ip'], 'type' => 'ipv4'];
                }
            }
        }

        // Fall back to IPv6 (DNS_AAAA)
        $records = @dns_get_record($host, DNS_AAAA);
        if ($records !== false && !empty($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    return ['ip' => $record['ipv6'], 'type' => 'ipv6'];
                }
            }
        }

        // Last resort: use gethostbyname for backward compatibility
        // This only supports IPv4 but is more reliable on some systems
        $ip = @gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ip' => $ip, 'type' => 'ipv4'];
        }

        return null;
    }

    /**
     * Get the reason why an IP is blocked, or null if it is allowed.
     *
     * Checks the explicit blocklist, then the IPv4 address carried by an
     * IPv6-mapped or IPv4-compatible address, then the blocked CIDR ranges.
     *
     * @param string $ip The IP address to check
     * @return string|null Human-readable block reason, or null if not blocked
     */
    private function getIpBlockReason(string $ip): ?string
    {
        if (in_array($ip, $this->blockedIps, true)) {
            return "IP is in explicit blocklist";
        }

        $mappedIpv4 = $this->extractMappedIpv4($ip);
        if ($mappedIpv4 !== null) {
            $ipv4Reason = $this->getIpBlockReason($mappedIpv4);
            if ($ipv4Reason !== null) {
                return "{$ipv4Reason} (via IPv6-mapped address)";
            }
        }

        foreach ($this->blockedRanges as $range => $label) {
            if ($this->ipInRange($ip, $range)) {
                return "IP is in {$label}, {$range}";
            }
        }

        return null;
    }

    /**
     * Check whether an IP address falls inside a CIDR range.
     *
     * Both sides go through inet_pton, so the comparison works on the
     * binary form and an IPv4 address never matches an IPv6 range.
     *
     * @param string $ip    The IP address to check
     * @param string $range The range in CIDR notation (e.g. 10.0.0.0/8, fc00::/7)
     * @return bool True if the address is inside the range
     */
    private function ipInRange(string $ip, string $range): bool
    {
        [$network, $bits] = array_pad(explode('/', $range, 2), 2, null);

        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);

        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if (substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
    }

    /**
     * Extract the underlying IPv4 address from an IPv6-mapped IPv4 address.
     *
     * Handles formats like ::ffff:127.0.0.1, ::ffff:7f00:1, and other
     * IPv4-compatible/mapped IPv6 representations.
     *
     * @param string $ip The IP address to check
     * @return string|null The extracted IPv4 address, or null if not a mapped address
     */
    private function extractMappedIpv4(string $ip): ?string
    {
        // Only process IPv6 addresses
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $lower = strtolower($ip);

        // ::ffff:x.x.x.x format (dotted-decimal mapped)
        if (preg_match('/^::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $lower, $matches)) {
            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $matches[1];
            }
        }

        // ::ffff:XXXX:XXXX format (hex mapped) — convert to dotted-decimal
        if (preg_match('/^::ffff:([0-9a-f]{1,4}):([0-9a-f]{1,4})$/i', $lower, $matches)) {
            $high = (int) hexdec($matches[1]);
            $low = (int) hexdec($matches[2]);
            $ipv4 = sprintf('%d.%d.%d.%d', ($high >> 8) & 0xFF, $high & 0xFF, ($low >> 8) & 0xFF, $low & 0xFF);
            if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ipv4;
            }
        }

        // IPv4-compatible ::x.x.x.x (deprecated but still exists)
        if (preg_match('/^::(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $lower, $matches)) {
            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Get the blocked domain rule that matches the given host, or null if not blocked.
     *
     * Checks for both exact domain matches and subdomain matches.
     *
     * @param string $host The lowercase hostname to check
     * @return string|null The matched rule description, or null if not blocked
     */
    private function getBlockedDomainMatch(string $host): ?string
    {
        // Check exact match
        if (in_array($host, $this->blockedDomains, true)) {
            return $host;
        }

        // Check subdomains
        foreach ($this->blockedDomains as $blockedDomain) {
            if (Str::endsWith($host, '.' . $blockedDomain)) {
                return $blockedDomain . ' (subdomain match)';
            }
        }

        return null;
    }

    /**
     * Validate URL structure and security.
     *
     * Checks URL format, scheme allowlist, host presence and destination
     * port. Path and query are left alone: once scheme, host and port are
     * settled, nothing in them changes what the proxy connects to, and cURL
     * reads the authority the same way parse_url() does.
     *
     * @param string $url        The URL to validate
     * @param string $resolvedIp The pre-resolved IP address for the URL's host
     * @return array{valid: bool, reason?: string, details?: array<string, mixed>} Validation result
     */
    public function validateUrl(string $url, string $resolvedIp): array
    {
        // Normalize URL
        $url = $this->normalizeUrl($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'valid' => false,
                'reason' => 'Invalid URL format',
                'details' => [],
            ];
        }

        $parsed = parse_url($url);

        if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), $this->allowedSchemes, true)) {
            return [
                'valid' => false,
                'reason' => 'Scheme not allowed',
                'details' => [
                    'scheme' => $parsed['scheme'] ?? 'none',
                    'allowed' => implode(', ', $this->allowedSchemes),
                ],
            ];
        }

        if (!isset($parsed['host']) || empty($parsed['host'])) {
            return [
                'valid' => false,
                'reason' => 'Missing host',
                'details' => [],
            ];
        }

        // Restrict to allowed ports to prevent port scanning
        if (isset($parsed['port'])) {
            if (!in_array($parsed['port'], $this->allowedPorts, true)) {
                Log::warning('Blocked request to non-standard port', [
                    'port' => $parsed['port'],
                    'host' => $parsed['host'] ?? 'unknown',
                ]);

                return [
                    'valid' => false,
                    'reason' => 'Non-standard port not allowed',
                    'details' => [
                        'port' => $parsed['port'],
                        'allowed' => implode(', ', $this->allowedPorts),
                    ],
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Normalize a URL by trimming whitespace, removing null bytes,
     * and stripping control characters.
     *
     * Does NOT urldecode the URL: percent-encoded bytes reach the remote
     * server as they are, decoding them here would change the URL.
     *
     * @param string $url The raw URL to normalize
     * @return string The cleaned URL
     */
    private function normalizeUrl(string $url): string
    {
        // Remove any whitespace
        $url = trim($url);

        // Remove potential null bytes
        $url = str_replace("\0", '', $url);

        // Remove characters that are never valid in URLs
        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);

        return $url;
    }
}
