<?php

namespace App\Services;

use App\Models\CustomerMealHold;
use App\Models\Holiday;
use App\Models\Subscription;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionDateService
{
    public function calculateOriginalEndDate(CarbonInterface|string $startDate, int $serviceDays): Carbon
    {
        return Carbon::parse($startDate)->startOfDay()->addDays(max(1, $serviceDays) - 1);
    }

    public function recalculate(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription->refresh();
            $start = $subscription->start_date->copy()->startOfDay();
            $originalEnd = $this->calculateOriginalEndDate($start, $subscription->subscription_days);
            $upperBound = $start->copy()->addDays(max(1000, $subscription->subscription_days * 3));
            $holidays = Holiday::active()->whereBetween('holiday_date', [$start, $upperBound])->get()->keyBy(fn ($holiday) => $holiday->holiday_date->toDateString());
            $holds = $subscription->mealHolds()->whereBetween('hold_date', [$start, $upperBound])->get()->keyBy(fn ($hold) => $hold->hold_date->toDateString());
            $cursor = $start->copy();
            $usedServiceDays = 0;
            $iterations = 0;
            $compensations = [];

            while ($usedServiceDays < $subscription->subscription_days) {
                if (++$iterations > max(1000, $subscription->subscription_days * 3)) {
                    throw new RuntimeException('Unable to calculate the subscription end date because too many consecutive non-service days exist.');
                }
                $key = $cursor->toDateString();
                $holiday = $holidays->get($key);
                $hold = $holds->get($key);
                if ($holiday) {
                    if ($holiday->isCompensation()) {
                        $compensations[$key] = ['compensation_type' => 'holiday', 'reason' => $holiday->title];
                    } else {
                        $usedServiceDays++;
                    }
                } elseif ($hold && $this->isFullDayHold($subscription, $hold)) {
                    $compensations[$key] = ['compensation_type' => 'meal_hold', 'reason' => $hold->reason ?: 'Full day meal hold'];
                } else {
                    $usedServiceDays++;
                }
                $cursor->addDay();
            }

            $finalEnd = $cursor->copy()->subDay();
            $subscription->compensations()->delete();
            foreach ($compensations as $date => $data) {
                $subscription->compensations()->create($data + [
                    'customer_id' => $subscription->customer_id,
                    'compensation_date' => $date,
                    'days_added' => 1,
                ]);
            }
            $holidayDays = collect($compensations)->where('compensation_type', 'holiday')->count();
            $holdDays = collect($compensations)->where('compensation_type', 'meal_hold')->count();
            $subscription->update([
                'original_end_date' => $originalEnd,
                'end_date' => $finalEnd,
                'holiday_compensation_days' => $holidayDays,
                'meal_hold_compensation_days' => $holdDays,
            ]);
            $this->syncExpiryStatus($subscription);
            return $subscription->refresh();
        });
    }

    public function recalculateAffectedByDate(CarbonInterface|string $date): int
    {
        $date = Carbon::parse($date)->startOfDay();
        $count = 0;
        Subscription::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->chunkById(100, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) { $this->recalculate($subscription); $count++; }
            });
        return $count;
    }

    public function isHoliday(CarbonInterface|string $date): bool
    {
        $date = Carbon::parse($date);
        return $date->isSunday() || Holiday::active()->whereDate('holiday_date', $date)->exists();
    }

    public function isFullDayHold(Subscription $subscription, CustomerMealHold $hold): bool
    {
        return collect(['breakfast', 'lunch', 'dinner'])
            ->filter(fn ($meal) => $subscription->{$meal})
            ->every(fn ($meal) => ! $hold->{$meal.'_required'});
    }

    public function isMealRequired(Subscription $subscription, ?CustomerMealHold $hold, string $meal): bool
    {
        return (bool) $subscription->{$meal} && (! $hold || (bool) $hold->{$meal.'_required'});
    }

    public function metrics(Subscription $subscription, CarbonInterface|string|null $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? today())->startOfDay();
        if ($asOf->lt($subscription->start_date)) $used = 0;
        else {
            $through = $asOf->min($subscription->end_date)->copy();
            $excluded = $subscription->compensations()->whereBetween('compensation_date', [$subscription->start_date, $through])->pluck('compensation_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->flip();
            $used = 0;
            for ($date = $subscription->start_date->copy(); $date <= $through; $date->addDay()) if (! $excluded->has($date->toDateString())) $used++;
        }
        $used = min($used, (int) $subscription->subscription_days);
        return [
            'original_end_date' => $subscription->original_end_date ?? $this->calculateOriginalEndDate($subscription->start_date, $subscription->subscription_days),
            'holiday_days' => (int) $subscription->holiday_compensation_days,
            'meal_hold_days' => (int) $subscription->meal_hold_compensation_days,
            'compensation_days' => (int) $subscription->holiday_compensation_days + (int) $subscription->meal_hold_compensation_days,
            'final_end_date' => $subscription->end_date,
            'used_service_days' => $used,
            'remaining_service_days' => max(0, (int) $subscription->subscription_days - $used),
        ];
    }

    private function syncExpiryStatus(Subscription $subscription): void
    {
        if (in_array($subscription->status, ['cancelled', 'paused'], true)) return;
        $status = today()->gt($subscription->end_date) ? 'expired' : 'active';
        $subscription->update(['status' => $status]);
        if ($subscription->customer->currentSubscription?->is($subscription)) $subscription->customer->update(['status' => $status]);
    }
}
