<?php

namespace App\Repositories\Eloquent;

use App\Models\Domain;
use App\Repositories\Contracts\DomainRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentDomainRepository implements DomainRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = Domain::query()->with('user:id,name,email');
        if (! empty($filters['user_id']))     $q->where('user_id', $filters['user_id']);
        if (! empty($filters['ssl_status']))  $q->where('ssl_status', $filters['ssl_status']);
        if (! empty($filters['search']))      $q->where('domain', 'like', "%{$filters['search']}%");
        return $q->orderByDesc('id')->paginate($perPage);
    }

    public function findById(int $id): ?Domain
    {
        return Domain::find($id);
    }

    public function findByName(string $name): ?Domain
    {
        return Domain::where('domain', $name)->first();
    }

    public function create(array $data): Domain
    {
        return Domain::create($data);
    }

    public function update(Domain $domain, array $data): Domain
    {
        $domain->update($data);
        return $domain;
    }

    public function delete(Domain $domain): void
    {
        $domain->delete();
    }
}
