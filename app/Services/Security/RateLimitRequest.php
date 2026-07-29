<?php

namespace App\Services\Security;

/**
 * DTO carrying the parts of a request that the RateLimiter uses to
 * build the bucket key (ip / user / path / domain).
 */
class RateLimitRequest
{
    public function __construct(
        public readonly ?string $ip = null,
        public readonly ?int $userId = null,
        public readonly string $path = '/',
        public readonly ?string $domain = null,
        public readonly string $method = 'GET',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['ip']     ?? null,
            $data['user_id'] ?? $data['userId'] ?? null,
            (string) ($data['path']  ?? '/'),
            $data['domain'] ?? null,
            strtoupper($data['method'] ?? 'GET'),
        );
    }
}
