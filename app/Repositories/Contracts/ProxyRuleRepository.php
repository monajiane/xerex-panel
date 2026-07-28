<?php

namespace App\Repositories\Contracts;

use App\Models\ProxyRule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProxyRuleRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;
    public function findById(int $id): ?ProxyRule;
    public function rulesForEdge(int $edgeId): Collection;
    public function create(array $data): ProxyRule;
    public function update(ProxyRule $rule, array $data): ProxyRule;
    public function delete(ProxyRule $rule): void;
}
