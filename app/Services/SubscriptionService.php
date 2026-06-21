<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionRenewal;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function __construct(private SubscriptionDateService $dates) {}

    public function create(Customer $customer, array $data, ?int $userId = null): Subscription
    {
        return DB::transaction(function () use ($customer, $data, $userId) {
            $data = $this->normalize($data);
            $subscription = $customer->subscriptions()->create($data + [
                'subscription_no' => $this->nextNumber(),
                'created_by' => $userId,
            ]);
            $customer->update(['status' => 'active', 'paused_at' => null]);
            return $this->dates->recalculate($subscription);
        });
    }

    public function renew(Customer $customer, array $data, ?int $userId = null): Subscription
    {
        return DB::transaction(function () use ($customer, $data, $userId) {
            $previous = $customer->subscriptions()->latest('id')->first();
            if ($previous && Carbon::parse($data['start_date'])->lte($previous->end_date)) throw ValidationException::withMessages(['start_date' => 'Renewal must start after the previous final extended end date of '.$previous->end_date->format('d-m-Y').'.']);
            $subscription = $this->create($customer, $data, $userId);
            SubscriptionRenewal::create([
                'customer_id' => $customer->id,
                'previous_subscription_id' => $previous?->id,
                'new_subscription_id' => $subscription->id,
                'renewed_on' => today(),
                'notes' => $data['renewal_notes'] ?? null,
                'created_by' => $userId,
            ]);
            return $subscription;
        });
    }

    public function pause(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $customer->update(['status' => 'paused', 'paused_at' => now()]);
            $customer->subscriptions()->where('status', 'active')->update(['status' => 'paused', 'paused_at' => now()]);
        });
    }

    public function resume(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $subscription = $customer->subscriptions()->where('status', 'paused')->latest('id')->firstOrFail();
            $pausedDays = $subscription->paused_at ? $subscription->paused_at->startOfDay()->diffInDays(today()) : 0;
            $newEndDate = $subscription->end_date->copy()->addDays((int) $pausedDays);
            $subscription->update([
                'status' => $newEndDate->isPast() ? 'expired' : 'active',
                'end_date' => $newEndDate,
                'resumed_at' => now(),
                'paused_at' => null,
            ]);
            $customer->update(['status' => $subscription->status, 'paused_at' => null]);
        });
    }

    public function expireDue(): int
    {
        return DB::transaction(function () {
            $ids = Subscription::where('status', 'active')->whereDate('end_date', '<', today())->pluck('customer_id');
            $count = Subscription::where('status', 'active')->whereDate('end_date', '<', today())->update(['status' => 'expired']);
            Customer::whereIn('id', $ids)->whereDoesntHave('subscriptions', fn ($q) => $q->where('status', 'active'))->update(['status' => 'expired']);
            return $count;
        });
    }

    private function normalize(array $data): array
    {
        $meals = collect(['breakfast', 'lunch', 'dinner'])->mapWithKeys(fn ($meal) => [$meal => (bool) ($data[$meal] ?? false)]);
        $mealCount = $meals->filter()->count();
        if ($mealCount === 0) throw ValidationException::withMessages(['meals' => 'Select at least one meal.']);
        $days = (int) ($data['subscription_days'] ?? Setting::value('default_subscription_days', 30));
        $start = Carbon::parse($data['start_date'] ?? today());
        $package = [1 => 'one_time', 2 => 'two_time', 3 => 'three_time'][$mealCount];
        $defaultPrice = Setting::value("{$package}_package_price", 0);

        return Arr::only($data, ['amount']) + $meals->all() + [
            'start_date' => $start,
            'end_date' => $start->copy()->addDays($days - 1),
            'subscription_days' => $days,
            'meal_count' => $mealCount,
            'package_type' => $package,
            'amount' => $data['amount'] ?? $defaultPrice,
            'payment_status' => 'pending',
            'status' => 'active',
        ];
    }

    private function nextNumber(): string
    {
        do { $number = 'SUB-'.now()->format('ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT); }
        while (Subscription::where('subscription_no', $number)->exists());
        return $number;
    }
}
