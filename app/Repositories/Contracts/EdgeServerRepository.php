<?php

namespace App\Repositories\Contracts;

use App\Models\EdgeServer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface EdgeServerRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;
    public function findById(int $id): ?EdgeServer;
    public function findByUuid(string $uuid): ?EdgeServer;
    public function online(): Collection;
    public function create(array $data): EdgeServer;
    public function update(EdgeServer $edge, array $data): EdgeServer;
    public function delete(EdgeServer $edge): void;
}
