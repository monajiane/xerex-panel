<?php

namespace App\Repositories\Contracts;

use App\Models\Domain;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DomainRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;
    public function findById(int $id): ?Domain;
    public function findByName(string $name): ?Domain;
    public function create(array $data): Domain;
    public function update(Domain $domain, array $data): Domain;
    public function delete(Domain $domain): void;
}
