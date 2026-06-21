<?php

namespace App\Services;

use App\Models\CustomerMealHold;
use App\Models\Delivery;
use App\Models\Subscription;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MealHoldService
{
    public function __construct(private SubscriptionDateService $dates, private DeliveryService $deliveries) {}

    public function createSingleDateHold(Subscription $subscription, Carbon|string $date, array $mealStatuses, array $details = [], ?int $userId = null): array
    {
        return $this->createDateRangeHold($subscription, $date, $date, $mealStatuses, $details, $userId);
    }

    public function createDateRangeHold(Subscription $subscription, Carbon|string $from, Carbon|string $to, array $mealStatuses, array $details = [], ?int $userId = null): array
    {
        [$from, $to] = $this->validateHoldDates($subscription, $from, $to, $mealStatuses);
        return DB::transaction(function () use ($subscription, $from, $to, $mealStatuses, $details, $userId) {
            $created = 0; $updated = 0;
            foreach (CarbonPeriod::create($from, $to) as $date) {
                $values = $this->requiredValues($subscription, $mealStatuses);
                $existing = CustomerMealHold::where('subscription_id', $subscription->id)->whereDate('hold_date', $date)->first();
                $hold = CustomerMealHold::updateOrCreate(
                    ['subscription_id' => $subscription->id, 'hold_date' => $date->copy()->startOfDay()],
                    $values + ['customer_id' => $subscription->customer_id, 'is_full_day_hold' => ! $this->hasRequiredMeal($subscription, $mealStatuses), 'reason' => $details['reason'] ?? null, 'notes' => $details['notes'] ?? null, 'created_by' => $userId]
                );
                $existing ? $updated++ : $created++;
                $this->syncDeliveriesForDate($subscription, $hold);
            }
            $this->updateSubscriptionEndDate($subscription);
            $preview = $this->generatePreview($subscription->refresh(), $from, $to, $mealStatuses);
            return ['created' => $created, 'updated' => $updated, 'days' => $from->diffInDays($to) + 1, 'compensation_days' => $preview['compensation_days'], 'full_hold_days' => $preview['full_hold_days']];
        });
    }

    public function validateHoldDates(Subscription $subscription, Carbon|string $from, Carbon|string $to, array $mealStatuses): array
    {
        $from = Carbon::parse($from)->startOfDay(); $to = Carbon::parse($to)->startOfDay();
        if ($from->gt($to)) throw ValidationException::withMessages(['to_date' => 'To date must be on or after the from date.']);
        if ($from->lt($subscription->start_date) || $to->gt($subscription->end_date)) throw ValidationException::withMessages(['from_date' => 'Every hold date must be inside the selected subscription period.']);
        $activeMeals = collect(['breakfast', 'lunch', 'dinner'])->filter(fn ($meal) => $subscription->{$meal});
        if ($activeMeals->isEmpty() || $activeMeals->contains(fn ($meal) => ! in_array($mealStatuses[$meal] ?? null, ['required', 'not_required'], true))) {
            throw ValidationException::withMessages(['meals' => 'Choose Required or Not Required for every active meal.']);
        }
        return [$from, $to];
    }

    public function calculateCompensation(Subscription $subscription, Carbon|string $date, array $mealStatuses): bool
    {
        return $this->dates->isHoliday($date) || ! $this->hasRequiredMeal($subscription, $mealStatuses);
    }

    public function updateSubscriptionEndDate(Subscription $subscription): Subscription
    {
        return $this->dates->recalculate($subscription);
    }

    public function generatePreview(Subscription $subscription, Carbon|string $from, Carbon|string $to, array $mealStatuses): array
    {
        [$from, $to] = $this->validateHoldDates($subscription, $from, $to, $mealStatuses);
        $rows = collect(); $compensationDays = 0; $fullHoldDays = 0;
        foreach (CarbonPeriod::create($from, $to) as $date) {
            $holiday = $this->dates->isHoliday($date);
            $hasRequired = $this->hasRequiredMeal($subscription, $mealStatuses);
            $compensated = $holiday || ! $hasRequired;
            if ($compensated) $compensationDays++;
            if (! $holiday && ! $hasRequired) $fullHoldDays++;
            $row = ['date' => $date->format('d-m-Y'), 'day' => $date->format('l')];
            foreach (['breakfast', 'lunch', 'dinner'] as $meal) $row[$meal] = $holiday ? 'Holiday' : (! $subscription->{$meal} ? 'Not Subscribed' : (($mealStatuses[$meal] ?? null) === 'required' ? 'Required' : 'Not Required'));
            $row['counted'] = ! $holiday && $hasRequired;
            $row['compensation'] = $compensated;
            $rows->push($row);
        }
        return ['rows' => $rows, 'total_days' => $rows->count(), 'compensation_days' => $compensationDays, 'full_hold_days' => $fullHoldDays];
    }

    private function hasRequiredMeal(Subscription $subscription, array $statuses): bool
    {
        return collect(['breakfast', 'lunch', 'dinner'])->contains(fn ($meal) => $subscription->{$meal} && ($statuses[$meal] ?? null) === 'required');
    }

    private function requiredValues(Subscription $subscription, array $statuses): array
    {
        return collect(['breakfast', 'lunch', 'dinner'])->mapWithKeys(fn ($meal) => [$meal.'_required' => (bool) $subscription->{$meal} && ($statuses[$meal] ?? null) === 'required'])->all();
    }

    private function syncDeliveriesForDate(Subscription $subscription, CustomerMealHold $hold): void
    {
        foreach (['breakfast', 'lunch', 'dinner'] as $meal) {
            if (! $hold->{$meal.'_required'} || $this->dates->isHoliday($hold->hold_date)) Delivery::where('subscription_id', $subscription->id)->whereDate('delivery_date', $hold->hold_date)->where('meal_type', $meal)->where('status', 'pending')->delete();
        }
        $this->deliveries->generateForDate($hold->hold_date);
    }
}
