<?php

namespace App\Repositories;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentCustomerRepository implements CustomerRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Customer::query()->with('currentSubscription')
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['place'] ?? null, fn ($q, $place) => $q->where('place', $place))
            ->latest()->paginate($perPage)->withQueryString();
    }

    public function create(array $data): Customer { return Customer::create($data); }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);
        return $customer->refresh();
    }
}
