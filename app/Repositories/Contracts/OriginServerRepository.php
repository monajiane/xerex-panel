<?php

namespace App\Repositories\Contracts;

use App\Models\OriginServer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OriginServerRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;
    public function findById(int $id): ?OriginServer;
    public function create(array $data): OriginServer;
    public function update(OriginServer $origin, array $data): OriginServer;
    public function delete(OriginServer $origin): void;
}
