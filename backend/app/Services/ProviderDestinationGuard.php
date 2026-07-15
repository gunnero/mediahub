<?php

namespace App\Services;

use Closure;
use RuntimeException;

class ProviderDestinationGuard
{
    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /** @param (Closure(string): list<string>)|null $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? $this->systemResolver(...);
    }

    /** @return array{url:string,curl_resolve:list<string>} */
    public function authorize(string $url): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('provider_url_invalid');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $this->isLocalHostname($host)) {
            throw new RuntimeException('provider_url_not_allowed');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('provider_url_not_allowed');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        $addresses = array_values(array_unique(array_filter($addresses, fn (mixed $address): bool => is_string($address) && $address !== '')));
        if ($addresses === [] || collect($addresses)->contains(fn (string $address): bool => ! $this->isPublicAddress($address))) {
            throw new RuntimeException('provider_url_not_allowed');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['url' => $url, 'curl_resolve' => []];
        }

        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('provider_unavailable');
        }

        $pinnedAddresses = implode(',', array_map(
            fn (string $address): string => str_contains($address, ':') ? '['.$address.']' : $address,
            $addresses,
        ));

        return [
            'url' => $url,
            'curl_resolve' => [$host.':'.$port.':'.$pinnedAddresses],
        ];
    }

    /** @return list<string> */
    private function systemResolver(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isLocalHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local');
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
