<?php

namespace App\Repositories\Eloquent;

use App\Models\EdgeServer;
use App\Repositories\Contracts\EdgeServerRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentEdgeServerRepository implements EdgeServerRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = EdgeServer::query();
        if (! empty($filters['status']))   $q->where('status', $filters['status']);
        if (! empty($filters['location'])) $q->where('location', 'like', "%{$filters['location']}%");
        if (! empty($filters['search'])) {
            $q->where(function ($w) use ($filters) {
                $w->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('hostname', 'like', "%{$filters['search']}%")
                  ->orWhere('ip_address', 'like', "%{$filters['search']}%");
            });
        }
        return $q->orderByDesc('id')->paginate($perPage);
    }

    public function findById(int $id): ?EdgeServer
    {
        return EdgeServer::find($id);
    }

    public function findByUuid(string $uuid): ?EdgeServer
    {
        return EdgeServer::where('uuid', $uuid)->first();
    }

    public function online(): Collection
    {
        return EdgeServer::where('status', EdgeServer::STATUS_ONLINE)->get();
    }

    public function create(array $data): EdgeServer
    {
        return EdgeServer::create($data);
    }

    public function update(EdgeServer $edge, array $data): EdgeServer
    {
        $edge->update($data);
        return $edge;
    }

    public function delete(EdgeServer $edge): void
    {
        $edge->delete();
    }
}
