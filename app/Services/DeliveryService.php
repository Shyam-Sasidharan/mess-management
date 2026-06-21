<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Subscription;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class DeliveryService
{
    public function generateForDate(CarbonInterface $date): int
    {
        $count = 0;
        $deliveryDate = Carbon::parse($date->toDateString())->startOfDay();
        Subscription::with('customer')->where('status', 'active')
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->chunkById(100, function ($subscriptions) use ($deliveryDate, &$count) {
                foreach ($subscriptions as $subscription) {
                    foreach (['breakfast', 'lunch', 'dinner'] as $meal) {
                        if (! $subscription->{$meal}) continue;
                        Delivery::firstOrCreate([
                            'subscription_id' => $subscription->id,
                            'delivery_date' => $deliveryDate,
                            'meal_type' => $meal,
                        ], ['customer_id' => $subscription->customer_id, 'status' => 'pending']);
                        $count++;
                    }
                }
            });
        return $count;
    }
}
