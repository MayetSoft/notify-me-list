<?php
/**
 * SSRF-safe HTTP GET for feed URLs.
 *
 * - only http:// and https://, only ports 80 and 443, no credentials in URL;
 * - the host name is resolved once, every address must be public (no
 *   loopback, private, link-local, CGNAT, multicast, reserved, cloud
 *   metadata...), and the connection is pinned to that address so DNS
 *   rebinding cannot swap it afterwards;
 * - redirects are followed manually (max 3), each hop re-validated;
 * - connect/total timeouts and a 5 MB size cap;
 * - proxies from the environment are ignored.
 */
final class FeedFetcher
{
    const MAX_BYTES = 5242880;
    const MAX_REDIRECTS = 3;
    const CONNECT_TIMEOUT = 8;
    const TIMEOUT = 20;
    const ALLOWED_PORTS = [80, 443];

    /**
     * Validates the syntax of a feed URL (no network). Returns normalized
     * parts or throws InvalidArgumentException with a user-facing message.
     */
    public static function validateUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\s\x00-\x1F]/', $url)) {
            throw new InvalidArgumentException(t('feed.err_url_invalid'));
        }
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            throw new InvalidArgumentException(t('feed.err_url_invalid'));
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException(t('feed.err_url_scheme'));
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new InvalidArgumentException(t('feed.err_url_credentials'));
        }
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true) && !NM_ALLOW_PRIVATE_FEEDS) {
            throw new InvalidArgumentException(t('feed.err_url_port'));
        }
        $host = strtolower(trim($p['host'], '[]'));
        if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
            if (!NM_ALLOW_PRIVATE_FEEDS) {
                throw new InvalidArgumentException(t('feed.err_url_private'));
            }
        }
        $path = isset($p['path']) && $p['path'] !== '' ? $p['path'] : '/';
        if (isset($p['query'])) {
            $path .= '?' . $p['query'];
        }
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => $path, 'url' => $url];
    }

    /** True if $ip is a globally routable unicast address. */
    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $blocked = [
                '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
                '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
                '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            ];
            $long = ip2long($ip);
            foreach ($blocked as $cidr) {
                list($net, $bits) = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
            return true;
        }
        // IPv6
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return false;
        }
        // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96): check the embedded IPv4.
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return self::isPublicIp(inet_ntop(substr($bin, 12)));
        }
        $first = ord($bin[0]);
        $second = ord($bin[1]);
        if ($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15) . "\1") {
            return false; // :: and ::1
        }
        if (($first & 0xFE) === 0xFC) {
            return false; // fc00::/7 unique local
        }
        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return false; // fe80::/10 link-local
        }
        if ($first === 0xFF) {
            return false; // multicast
        }
        if ($first === 0x20 && $second === 0x01 && ord($bin[2]) === 0x0d && ord($bin[3]) === 0xb8) {
            return false; // 2001:db8::/32 documentation
        }
        if ($first === 0x20 && $second === 0x02) {
            return false; // 2002::/16 6to4 can embed private IPv4
        }
        return true;
    }

    /** Resolves $host and returns one public IP, or throws if any resolved IP is not public. */
    public static function resolvePublic(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = @gethostbynamel($host) ?: [];
            if (function_exists('dns_get_record')) {
                $aaaa = @dns_get_record($host, DNS_AAAA);
                foreach ($aaaa ?: [] as $r) {
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        if (!$ips) {
            throw new RuntimeException(t('feed.err_dns', ['host' => $host]));
        }
        if (!NM_ALLOW_PRIVATE_FEEDS) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new RuntimeException(t('feed.err_url_private'));
                }
            }
        }
        // Prefer IPv4: shared hosts often have no IPv6 connectivity.
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
        return $ips[0];
    }

    /**
     * Fetches a URL. Returns ['status' => 200|304, 'body' => string,
     * 'etag' => string, 'last_modified' => string, 'url' => final URL].
     */
    public static function fetch(string $url, string $etag = '', string $lastModified = ''): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = self::validateUrl($url);
            $ip = self::resolvePublic($parts['host']);
            $res = extension_loaded('curl')
                ? self::requestCurl($parts, $ip, $etag, $lastModified)
                : self::requestStream($parts, $ip, $etag, $lastModified);
            $status = $res['status'];
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($res['location'] === '') {
                    throw new RuntimeException(t('feed.err_http', ['status' => $status]));
                }
                $url = self::resolveRelative($url, $res['location']);
                continue;
            }
            if ($status === 304) {
                return ['status' => 304, 'body' => '', 'etag' => $etag, 'last_modified' => $lastModified, 'url' => $url];
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(t('feed.err_http', ['status' => $status]));
            }
            return ['status' => 200, 'body' => $res['body'], 'etag' => $res['etag'], 'last_modified' => $res['last_modified'], 'url' => $url];
        }
        throw new RuntimeException(t('feed.err_redirects'));
    }

    private static function userAgent(): string
    {
        return 'NotifyMeList/' . NM_VERSION . ' (feed reader; +' . nm_abs_url('') . ')';
    }

    private static function requestHeaders(string $etag, string $lastModified): array
    {
        $h = ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5'];
        if ($etag !== '') {
            $h[] = 'If-None-Match: ' . str_replace(["\r", "\n"], '', $etag);
        }
        if ($lastModified !== '') {
            $h[] = 'If-Modified-Since: ' . str_replace(["\r", "\n"], '', $lastModified);
        }
        return $h;
    }

    private static function requestCurl(array $p, string $ip, string $etag, string $lastModified): array
    {
        $body = '';
        $headers = [];
        $tooBig = false;
        $ch = curl_init();
        $url = $p['scheme'] . '://' . (strpos($p['host'], ':') !== false ? '[' . $p['host'] . ']' : $p['host']) . ':' . $p['port'] . $p['path'];
        $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$p['host'] . ':' . $p['port'] . ':' . $resolveIp],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_HTTPHEADER => self::requestHeaders($etag, $lastModified),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooBig) {
                $body .= $chunk;
                if (strlen($body) > self::MAX_BYTES) {
                    $tooBig = true;
                    return 0; // abort
                }
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($tooBig) {
            throw new RuntimeException(t('feed.err_too_big'));
        }
        if ($ok === false) {
            throw new RuntimeException(t('feed.err_network', ['error' => $err]));
        }
        return [
            'status' => $status,
            'body' => $body,
            'location' => isset($headers['location']) ? $headers['location'] : '',
            'etag' => isset($headers['etag']) ? $headers['etag'] : '',
            'last_modified' => isset($headers['last-modified']) ? $headers['last-modified'] : '',
        ];
    }

    /** Fallback when the curl extension is missing: PHP streams, pinned to the IP. */
    private static function requestStream(array $p, string $ip, string $etag, string $lastModified): array
    {
        $hostHeader = $p['host'] . ((($p['scheme'] === 'https' && $p['port'] !== 443) || ($p['scheme'] === 'http' && $p['port'] !== 80)) ? ':' . $p['port'] : '');
        $ipHost = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        $url = $p['scheme'] . '://' . $ipHost . ':' . $p['port'] . $p['path'];
        $headers = array_merge(['Host: ' . $hostHeader, 'User-Agent: ' . self::userAgent(), 'Connection: close'], self::requestHeaders($etag, $lastModified));
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'follow_location' => 0,
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
                'proxy' => '',
            ],
            'ssl' => [
                'peer_name' => $p['host'],
                'SNI_enabled' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $fh = @fopen($url, 'rb', false, $ctx);
        if (!$fh) {
            $e = error_get_last();
            throw new RuntimeException(t('feed.err_network', ['error' => $e ? $e['message'] : 'fopen']));
        }
        $body = stream_get_contents($fh, self::MAX_BYTES + 1);
        $meta = stream_get_meta_data($fh);
        fclose($fh);
        if ($body === false) {
            throw new RuntimeException(t('feed.err_network', ['error' => 'read']));
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException(t('feed.err_too_big'));
        }
        $status = 0;
        $h = [];
        foreach (isset($meta['wrapper_data']) ? (array) $meta['wrapper_data'] : [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $h = [];
            } elseif (($pos = strpos($line, ':')) !== false) {
                $h[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        // PHP streams do not decompress; we did not send Accept-Encoding, but be safe.
        if (isset($h['content-encoding']) && stripos($h['content-encoding'], 'gzip') !== false && function_exists('gzdecode')) {
            $dec = @gzdecode($body);
            if ($dec !== false) {
                $body = $dec;
            }
        }
        return [
            'status' => $status,
            'body' => $body,
            'location' => isset($h['location']) ? $h['location'] : '',
            'etag' => isset($h['etag']) ? $h['etag'] : '',
            'last_modified' => isset($h['last-modified']) ? $h['last-modified'] : '',
        ];
    }

    /** Resolves a (possibly relative) URL against a base URL. */
    public static function resolveRelative(string $base, string $rel): string
    {
        $rel = trim($rel);
        if ($rel === '') {
            return $base;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $rel)) {
            return $rel;
        }
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return $rel;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (substr($rel, 0, 2) === '//') {
            return $b['scheme'] . ':' . $rel;
        }
        if ($rel[0] === '/') {
            return $origin . $rel;
        }
        if ($rel[0] === '?') {
            return $origin . (isset($b['path']) ? $b['path'] : '/') . $rel;
        }
        if ($rel[0] === '#') {
            return $base;
        }
        $dir = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';
        $path = $dir . $rel;
        // Remove ./ and ../ segments.
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }
        $path = implode('/', $segments);
        return $origin . ($path !== '' && $path[0] === '/' ? '' : '/') . $path;
    }
}
