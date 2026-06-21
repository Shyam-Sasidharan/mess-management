<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Subscription;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class DeliveryService
{
    public function __construct(private SubscriptionDateService $dates) {}

    public function generateForDate(CarbonInterface $date): int
    {
        if ($this->dates->isHoliday($date)) {
            Delivery::whereDate('delivery_date', $date)->where('status', 'pending')->delete();
            return 0;
        }
        $count = 0;
        $deliveryDate = Carbon::parse($date->toDateString())->startOfDay();
        Subscription::with('customer')->where('status', 'active')
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->chunkById(100, function ($subscriptions) use ($deliveryDate, &$count) {
                foreach ($subscriptions as $subscription) {
                    $hold = $subscription->mealHolds()->whereDate('hold_date', $deliveryDate)->first();
                    foreach (['breakfast', 'lunch', 'dinner'] as $meal) {
                        if (! $this->dates->isMealRequired($subscription, $hold, $meal)) continue;
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
