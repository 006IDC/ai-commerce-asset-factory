<?php

declare(strict_types=1);

namespace app\support;

use RuntimeException;

final class RemoteMediaUrlGuard
{
    /**
     * Validate an untrusted media URL and return a cURL DNS pin.
     * Redirects must remain disabled by the caller so every destination is
     * validated independently.
     *
     * @return array{url:string,resolve:list<string>}
     */
    public static function publicHttpsTarget(string $url): array
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('远程媒体地址无效');
        }

        $localTestTarget = self::explicitLocalCliTestTarget($url);
        if ($localTestTarget !== null) {
            return $localTestTarget;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('远程媒体地址必须使用不含凭据或片段的 HTTPS URL');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new RuntimeException('远程媒体地址不能指向本机或本地域名');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port !== 443) {
            throw new RuntimeException('远程媒体地址只能使用 HTTPS 标准端口');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : self::resolveAddresses($host);
        if ($addresses === []) {
            throw new RuntimeException('远程媒体域名无法解析');
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                throw new RuntimeException('远程媒体地址解析到了私网或保留地址');
            }
        }

        $selected = (string) $addresses[0];
        if (str_contains($selected, ':')) {
            $selected = '[' . trim($selected, '[]') . ']';
        }

        return [
            'url' => $url,
            'resolve' => [$host . ':443:' . $selected],
        ];
    }

    /**
     * Keep the production policy strict while allowing the offline smoke suite
     * to download from its exact loopback mock origin. The override is only
     * available to CLI processes and must be opted into explicitly.
     *
     * @return array{url:string,resolve:list<string>}|null
     */
    private static function explicitLocalCliTestTarget(string $url): ?array
    {
        if (PHP_SAPI !== 'cli') {
            return null;
        }

        $origin = rtrim(trim((string) getenv('ASSET_FACTORY_REMOTE_MEDIA_TEST_ORIGIN')), '/');
        if ($origin === '') {
            return null;
        }

        $originParts = parse_url($origin);
        $urlParts = parse_url($url);
        if (!is_array($originParts) || !is_array($urlParts)) {
            return null;
        }

        $originHost = strtolower(trim((string) ($originParts['host'] ?? ''), '[]'));
        $urlHost = strtolower(trim((string) ($urlParts['host'] ?? ''), '[]'));
        $originPort = (int) ($originParts['port'] ?? 0);
        $urlPort = (int) ($urlParts['port'] ?? 0);
        if (($originParts['scheme'] ?? '') !== 'http'
            || $originHost !== '127.0.0.1'
            || $originPort < 1024
            || $originPort > 65535
            || ($urlParts['scheme'] ?? '') !== 'http'
            || $urlHost !== $originHost
            || $urlPort !== $originPort
            || isset($urlParts['user'])
            || isset($urlParts['pass'])
            || isset($urlParts['fragment'])
            || !str_starts_with($url, $origin . '/')) {
            return null;
        }

        return [
            'url' => $url,
            'resolve' => [$originHost . ':' . $originPort . ':' . $originHost],
        ];
    }

    /** @return list<string> */
    private static function resolveAddresses(string $host): array
    {
        $addresses = [];
        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ipv6 = trim((string) ($record['ipv6'] ?? ''));
                    if ($ipv6 !== '') {
                        $addresses[] = $ipv6;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $address): string => trim((string) $address), $addresses),
            static fn (string $address): bool => $address !== ''
        )));
    }
}
