<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\SslCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SslCertificate>
 */
class SslCertificateFactory extends Factory
{
    protected $model = SslCertificate::class;

    public function definition(): array
    {
        $domain = fake()->domainName();
        return [
            'uuid'        => (string) Str::uuid(),
            'domain_id'   => Domain::factory(),
            'common_name' => $domain,
            'subject_alt_names' => [$domain, 'www.' . $domain],
            'provider'    => 'letsencrypt',
            'status'      => SslCertificate::STATUS_PENDING,
            'cert_path'   => '/etc/letsencrypt/live/' . $domain . '/fullchain.pem',
            'key_path'    => '/etc/letsencrypt/live/' . $domain . '/privkey.pem',
            'chain_path'  => '/etc/letsencrypt/live/' . $domain . '/chain.pem',
            'issuer'      => "Let's Encrypt Authority X3",
            'serial_number' => (string) Str::random(32),
            'fingerprint_sha256' => hash('sha256', Str::random(64)),
            'issued_at'   => now()->subDays(7),
            'expires_at'  => now()->addDays(60),
            'auto_renew'  => true,
            'meta'        => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status'     => SslCertificate::STATUS_ACTIVE,
            'issued_at'  => now()->subDays(7),
            'expires_at' => now()->addDays(60),
        ]);
    }

    public function expiring(): static
    {
        return $this->state(fn () => [
            'status'     => SslCertificate::STATUS_EXPIRING,
            'expires_at' => now()->addDays(5),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status'     => SslCertificate::STATUS_EXPIRED,
            'expires_at' => now()->subDays(1),
        ]);
    }
}
