<?php

namespace App\Services\Dns;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PowerDNSService - manages DNS zones and records via the PowerDNS HTTP API.
 *
 * The PowerDNS API uses a separate write endpoint (`/api/v1/servers/{server}/zones`).
 * Authentication is done via the X-API-Key header.
 *
 * Reference: https://doc.powerdns.com/authoritative/http-api/index.html
 */
class PowerDNSService
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $server = 'localhost',
    ) {}

    public static function make(): self
    {
        return new self(
            baseUrl: rtrim((string) config('xerex.powerdns.api_url'), '/'),
            apiKey:  (string) config('xerex.powerdns.api_key'),
            server:  'localhost',
        );
    }

    public function health(): array
    {
        try {
            $resp = $this->http()->get($this->url('/api/v1/servers'));
            return ['ok' => $resp->successful(), 'servers' => $resp->json()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a new DNS zone.
     * If $nameservers is null, defaults are used.
     */
    public function createZone(string $zoneName, ?array $nameservers = null, ?string $soaContent = null): DnsZone
    {
        $ns = $nameservers ?? ['ns1.xerex.local.', 'ns2.xerex.local.'];
        $soa = $soaContent ?? (string) config('xerex.powerdns.default_soa');

        // Create in PowerDNS
        $payload = [
            'name'         => $zoneName,
            'kind'         => 'Native',
            'masters'      => [],
            'nameservers'  => $ns,
            'soa'          => $soa,
            'rrsets'       => [
                [
                    'name'       => $zoneName,
                    'type'       => 'SOA',
                    'ttl'        => 3600,
                    'changetype' => 'REPLACE',
                    'records'    => [['content' => $soa, 'disabled' => false]],
                ],
                [
                    'name'       => $zoneName,
                    'type'       => 'NS',
                    'ttl'        => 3600,
                    'changetype' => 'REPLACE',
                    'records'    => array_map(fn ($n) => ['content' => $n, 'disabled' => false], $ns),
                ],
            ],
        ];

        $resp = $this->http()->post($this->url("/api/v1/servers/{$this->server}/zones"), $payload);

        if (! $resp->successful()) {
            throw new \RuntimeException("PowerDNS create zone failed: " . $resp->body());
        }

        return DnsZone::create([
            'zone'              => $zoneName,
            'provider'          => 'powerdns',
            'provider_zone_id'  => $resp->json('id'),
            'status'            => DnsZone::STATUS_ACTIVE,
            'soa'               => $soa,
            'nameservers'       => $ns,
        ]);
    }

    public function deleteZone(DnsZone $zone): void
    {
        $this->http()->delete($this->url("/api/v1/servers/{$this->server}/zones/{$zone->zone}"));
        $zone->delete();
    }

    public function addRecord(DnsZone $zone, string $name, string $type, string $value, int $ttl = 300, ?int $priority = null): DnsRecord
    {
        $fqdn = $name === '@' ? $zone->zone : "{$name}.{$zone->zone}";

        $rrset = [
            'name'       => $fqdn,
            'type'       => $type,
            'ttl'        => $ttl,
            'changetype' => 'REPLACE',
            'records'    => [['content' => $value, 'disabled' => false]],
        ];

        $resp = $this->http()->patch(
            $this->url("/api/v1/servers/{$this->server}/zones/{$zone->zone}"),
            ['rrsets' => [$rrset]]
        );

        if (! $resp->successful()) {
            throw new \RuntimeException("PowerDNS add record failed: " . $resp->body());
        }

        return DnsRecord::create([
            'dns_zone_id'        => $zone->id,
            'name'               => $name,
            'type'               => $type,
            'value'              => $value,
            'ttl'                => $ttl,
            'priority'           => $priority,
            'provider_record_id' => $fqdn,
        ]);
    }

    public function deleteRecord(DnsRecord $record): void
    {
        $zone = $record->zone;
        $fqdn = $record->getFqdn();

        $resp = $this->http()->patch(
            $this->url("/api/v1/servers/{$this->server}/zones/{$zone->zone}"),
            [
                'rrsets' => [
                    [
                        'name'       => $fqdn,
                        'type'       => $record->type,
                        'changetype' => 'DELETE',
                    ],
                ],
            ]
        );

        if (! $resp->successful()) {
            Log::warning("PowerDNS delete record failed: " . $resp->body());
        }

        $record->delete();
    }

    /**
     * Sync all A/CNAME records for a domain based on its edge servers.
     * Useful when an edge is added/removed.
     */
    public function syncDomainRecords(Domain $domain): void
    {
        $zone = DnsZone::firstOrCreate(
            ['zone' => $domain->domain],
            [
                'provider'    => 'powerdns',
                'status'      => DnsZone::STATUS_PENDING,
                'soa'         => (string) config('xerex.powerdns.default_soa'),
                'nameservers' => ['ns1.xerex.local.', 'ns2.xerex.local.'],
            ]
        );

        if ($zone->status !== DnsZone::STATUS_ACTIVE) {
            $this->createZone($domain->domain);
            $zone->refresh();
        }

        // Apex A record points to all online edges
        $edges = \App\Models\EdgeServer::where('status', \App\Models\EdgeServer::STATUS_ONLINE)->get();
        foreach ($edges as $edge) {
            $this->addRecord($zone, '@', 'A', $edge->ip_address, 300);
        }

        $domain->update([
            'dns_status'      => Domain::SSL_ACTIVE === 'active' ? 'active' : 'pending',
            'dns_verified_at' => now(),
        ]);
    }

    protected function http()
    {
        return Http::withHeaders([
            'X-API-Key' => $this->apiKey,
            'Accept'    => 'application/json',
        ])->timeout(10);
    }

    protected function url(string $path): string
    {
        return $this->baseUrl . $path;
    }
}
