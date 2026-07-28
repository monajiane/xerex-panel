<?php

namespace App\Repositories\Eloquent;

use App\Models\OriginServer;
use App\Repositories\Contracts\OriginServerRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentOriginServerRepository implements OriginServerRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = OriginServer::query();
        if (! empty($filters['user_id']))        $q->where('user_id', $filters['user_id']);
        if (! empty($filters['health_status']))  $q->where('health_status', $filters['health_status']);
        if (! empty($filters['search'])) {
            $q->where(function ($w) use ($filters) {
                $w->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('host', 'like', "%{$filters['search']}%");
            });
        }
        return $q->orderByDesc('id')->paginate($perPage);
    }

    public function findById(int $id): ?OriginServer
    {
        return OriginServer::find($id);
    }

    public function create(array $data): OriginServer
    {
        return OriginServer::create($data);
    }

    public function update(OriginServer $origin, array $data): OriginServer
    {
        $origin->update($data);
        return $origin;
    }

    public function delete(OriginServer $origin): void
    {
        $origin->delete();
    }
}
