<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function create(array $data): Customer;
    public function update(Customer $customer, array $data): Customer;
}
