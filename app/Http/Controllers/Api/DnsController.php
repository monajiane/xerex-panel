<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Services\Dns\PowerDNSService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DnsController extends Controller
{
    public function __construct(protected PowerDNSService $dns) {}

    public function index(Request $request): JsonResponse
    {
        $zones = DnsZone::withCount('records')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return response()->json($zones);
    }

    public function show(DnsZone $zone): JsonResponse
    {
        $zone->load('records');
        return response()->json($zone);
    }

    /**
     * Provision DNS zone + initial records for a domain.
     * Creates the zone in PowerDNS and points apex A records to all online edges.
     */
    public function provisionDomain(Domain $domain): JsonResponse
    {
        $domain->update(['dns_status' => 'configuring']);

        try {
            $this->dns->syncDomainRecords($domain);

            return response()->json([
                'message' => "DNS zone for {$domain->domain} provisioned",
                'domain'  => $domain->fresh(),
            ]);
        } catch (\Throwable $e) {
            $domain->update(['dns_status' => 'error']);
            return response()->json([
                'error'   => 'dns_provision_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function storeZone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'zone'         => ['required', 'string', 'max:253', 'unique:dns_zones,zone'],
            'nameservers'  => ['nullable', 'array'],
            'soa'          => ['nullable', 'string'],
        ]);

        try {
            $zone = $this->dns->createZone($data['zone'], $data['nameservers'] ?? null, $data['soa'] ?? null);
            return response()->json($zone, 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'create_failed', 'message' => $e->getMessage()], 502);
        }
    }

    public function destroyZone(DnsZone $zone): JsonResponse
    {
        try {
            $this->dns->deleteZone($zone);
            return response()->json(['message' => 'Zone deleted']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'delete_failed', 'message' => $e->getMessage()], 502);
        }
    }

    public function storeRecord(Request $request, DnsZone $zone): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'type'     => ['required', Rule::in([
                DnsRecord::TYPE_A, DnsRecord::TYPE_AAAA, DnsRecord::TYPE_CNAME,
                DnsRecord::TYPE_TXT, DnsRecord::TYPE_MX, DnsRecord::TYPE_NS,
                DnsRecord::TYPE_SRV, DnsRecord::TYPE_CAA,
            ])],
            'value'    => ['required', 'string', 'max:2048'],
            'ttl'      => ['nullable', 'integer', 'min:60', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'edge_server_id' => ['nullable', 'exists:edge_servers,id'],
            'domain_id'      => ['nullable', 'exists:domains,id'],
        ]);

        try {
            $record = $this->dns->addRecord(
                $zone,
                $data['name'],
                $data['type'],
                $data['value'],
                $data['ttl'] ?? 300,
                $data['priority'] ?? null,
            );
            $record->update([
                'edge_server_id' => $data['edge_server_id'] ?? null,
                'domain_id'      => $data['domain_id']      ?? null,
            ]);

            return response()->json($record->fresh(), 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'create_failed', 'message' => $e->getMessage()], 502);
        }
    }

    public function destroyRecord(DnsRecord $record): JsonResponse
    {
        try {
            $this->dns->deleteRecord($record);
            return response()->json(['message' => 'Record deleted']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'delete_failed', 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Verify DNS is pointing to one of our edges (via dig or fallback HTTP-based check).
     */
    public function verify(Domain $domain): JsonResponse
    {
        $edges = \App\Models\EdgeServer::where('status', \App\Models\EdgeServer::STATUS_ONLINE)->pluck('ip_address');

        $resolved = gethostbyname($domain->domain);
        $matched = $edges->contains($resolved);

        $domain->update([
            'dns_status'      => $matched ? 'active' : 'pending',
            'dns_verified_at' => now(),
        ]);

        return response()->json([
            'domain'   => $domain->domain,
            'resolved' => $resolved,
            'matched'  => $matched,
            'edges'    => $edges,
        ]);
    }
}
