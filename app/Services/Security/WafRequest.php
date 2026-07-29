<?php

namespace App\Services\Security;

/**
 * Lightweight DTO representing the parts of an HTTP request that the WAF
 * cares about. Constructed from a Symfony Request in the middleware but
 * can be created from any source (tests, edge-agent reports, etc).
 */
class WafRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $query = '',
        public readonly string $body = '',
        public readonly string $userAgent = '',
        public readonly array $headers = [],
        public readonly ?string $clientIp = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            strtoupper($data['method']    ?? 'GET'),
            (string) ($data['uri']        ?? '/'),
            (string) ($data['query']      ?? ''),
            (string) ($data['body']       ?? ''),
            (string) ($data['user_agent'] ?? $data['userAgent'] ?? ''),
            (array)  ($data['headers']    ?? []),
            $data['client_ip'] ?? $data['clientIp'] ?? null,
        );
    }

    /**
     * Extract the value of a named header (case-insensitive).
     */
    public function header(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $k => $v) {
            if (strtolower((string) $k) === $lower) {
                return is_array($v) ? ($v[0] ?? null) : (string) $v;
            }
        }
        return null;
    }

    public function toArray(): array
    {
        return [
            'method'    => $this->method,
            'uri'       => $this->uri,
            'query'     => $this->query,
            'body'      => $this->body,
            'userAgent' => $this->userAgent,
            'headers'   => $this->headers,
            'clientIp'  => $this->clientIp,
        ];
    }
}
