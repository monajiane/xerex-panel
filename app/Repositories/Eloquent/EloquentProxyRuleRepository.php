<?php

namespace App\Repositories\Eloquent;

use App\Models\ProxyRule;
use App\Repositories\Contracts\ProxyRuleRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentProxyRuleRepository implements ProxyRuleRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = ProxyRule::with(['domain:id,domain', 'edgeServer:id,name,hostname', 'originServer:id,name,host,port']);
        if (! empty($filters['type']))     $q->where('type', $filters['type']);
        if (isset($filters['enabled']))   $q->where('enabled', (bool) $filters['enabled']);
        if (! empty($filters['edge_server_id'])) $q->where('edge_server_id', $filters['edge_server_id']);
        if (! empty($filters['domain_id']))      $q->where('domain_id', $filters['domain_id']);
        return $q->orderBy('priority')->paginate($perPage);
    }

    public function findById(int $id): ?ProxyRule
    {
        return ProxyRule::find($id);
    }

    public function rulesForEdge(int $edgeId): Collection
    {
        return ProxyRule::with(['domain', 'originServer'])
            ->where('edge_server_id', $edgeId)
            ->where('enabled', true)
            ->get();
    }

    public function create(array $data): ProxyRule
    {
        return ProxyRule::create($data);
    }

    public function update(ProxyRule $rule, array $data): ProxyRule
    {
        $rule->update($data);
        return $rule;
    }

    public function delete(ProxyRule $rule): void
    {
        $rule->delete();
    }
}
