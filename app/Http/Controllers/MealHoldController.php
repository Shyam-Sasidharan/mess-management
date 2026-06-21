<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMealHold;
use App\Models\Delivery;
use App\Models\Subscription;
use App\Services\DeliveryService;
use App\Services\SubscriptionDateService;
use App\Services\MealHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MealHoldController extends Controller
{
    public function index(Request $request): View
    {
        $holds = CustomerMealHold::with(['customer', 'subscription'])
            ->when($request->customer, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('hold_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('hold_date', '<=', $v))
            ->when($request->place, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)))
            ->latest('hold_date')->paginate(20)->withQueryString();
        $subscriptions = Subscription::with('customer')->whereIn('status', ['active', 'paused'])->orderByDesc('start_date')->get();
        $dates = app(SubscriptionDateService::class);
        $subscriptionSummaries = $subscriptions->mapWithKeys(function ($subscription) use ($dates) {
            $metrics = $dates->metrics($subscription);
            return [$subscription->id => ['customer' => $subscription->customer->name, 'mobile' => $subscription->customer->primary_mobile, 'start' => $subscription->start_date->format('d M Y'), 'end' => $subscription->end_date->format('d M Y'), 'start_iso' => $subscription->start_date->toDateString(), 'end_iso' => $subscription->end_date->toDateString(), 'meals' => $subscription->meal_names, 'meal_flags' => ['breakfast' => $subscription->breakfast, 'lunch' => $subscription->lunch, 'dinner' => $subscription->dinner], 'remaining' => $metrics['remaining_service_days']]];
        });
        return view('meal-holds.index', ['holds' => $holds, 'subscriptions' => $subscriptions, 'subscriptionSummaries' => $subscriptionSummaries, 'customers' => Customer::orderBy('name')->get(), 'places' => Customer::whereNotNull('place')->distinct()->orderBy('place')->pluck('place')]);
    }

    public function store(Request $request, MealHoldService $service): RedirectResponse
    {
        $data = $this->rangeData($request);
        $subscription = Subscription::findOrFail($data['subscription_id']);
        [$from, $to] = $this->selectedDates($data);
        $result = $data['selection_mode'] === 'single'
            ? $service->createSingleDateHold($subscription, $from, $data['meal_statuses'], $data, $request->user()->id)
            : $service->createDateRangeHold($subscription, $from, $to, $data['meal_statuses'], $data, $request->user()->id);
        $message = "Meal holds saved for {$result['days']} day(s): {$result['created']} created, {$result['updated']} updated, {$result['compensation_days']} compensated.";
        return back()->with('success', $message);
    }

    public function preview(Request $request, MealHoldService $service): JsonResponse
    {
        $data = $this->rangeData($request);
        $subscription = Subscription::findOrFail($data['subscription_id']);
        [$from, $to] = $this->selectedDates($data);
        return response()->json($service->generatePreview($subscription, $from, $to, $data['meal_statuses']));
    }

    public function update(Request $request, CustomerMealHold $mealHold, SubscriptionDateService $dates, DeliveryService $deliveries): RedirectResponse
    {
        $oldSubscription = $mealHold->subscription;
        $oldDate = $mealHold->hold_date->copy();
        $data = $this->data($request, $mealHold);
        $subscription = Subscription::findOrFail($data['subscription_id']);
        $this->guard($subscription, $data, $mealHold);
        $mealHold->fill($data + ['customer_id' => $subscription->customer_id]);
        $mealHold->is_full_day_hold = $dates->isFullDayHold($subscription, $mealHold);
        $mealHold->save();
        $dates->recalculate($oldSubscription);
        $deliveries->generateForDate($oldDate);
        $this->refresh($mealHold, $dates, $deliveries);
        return back()->with('success', 'Meal hold updated.');
    }

    public function destroy(CustomerMealHold $mealHold, SubscriptionDateService $dates, DeliveryService $deliveries): RedirectResponse
    {
        $subscription = $mealHold->subscription;
        $date = $mealHold->hold_date->copy();
        $mealHold->delete();
        $dates->recalculate($subscription);
        $deliveries->generateForDate($date);
        return back()->with('success', 'Meal hold removed and subscription recalculated.');
    }

    private function data(Request $request, ?CustomerMealHold $hold = null): array
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'exists:subscriptions,id'], 'hold_date' => ['required', 'date'],
            'breakfast_required' => ['nullable', 'boolean'], 'lunch_required' => ['nullable', 'boolean'], 'dinner_required' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        foreach (['breakfast', 'lunch', 'dinner'] as $meal) $data[$meal.'_required'] = $request->boolean($meal.'_required');
        return $data;
    }

    private function rangeData(Request $request): array
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'exists:subscriptions,id'], 'selection_mode' => ['required', 'in:single,range'],
            'hold_date' => ['required_if:selection_mode,single', 'nullable', 'date'], 'from_date' => ['required_if:selection_mode,range', 'nullable', 'date'],
            'to_date' => ['required_if:selection_mode,range', 'nullable', 'date', 'after_or_equal:from_date'],
            'breakfast_status' => ['nullable', 'in:required,not_required'], 'lunch_status' => ['nullable', 'in:required,not_required'], 'dinner_status' => ['nullable', 'in:required,not_required'],
            'reason' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $data['meal_statuses'] = collect(['breakfast','lunch','dinner'])->mapWithKeys(fn ($meal) => [$meal => $request->input($meal.'_status')])->all();
        return $data;
    }

    private function selectedDates(array $data): array
    {
        return $data['selection_mode'] === 'single' ? [$data['hold_date'], $data['hold_date']] : [$data['from_date'], $data['to_date']];
    }

    private function guard(Subscription $subscription, array $data, ?CustomerMealHold $current = null): void
    {
        if (! $subscription->start_date->lte($data['hold_date']) || ! $subscription->end_date->gte($data['hold_date'])) throw ValidationException::withMessages(['hold_date' => 'Hold date must be inside the selected subscription period.']);
        if (CustomerMealHold::where('subscription_id', $subscription->id)->whereDate('hold_date', $data['hold_date'])->when($current, fn ($q) => $q->where('id', '!=', $current->id))->exists()) throw ValidationException::withMessages(['hold_date' => 'A meal requirement already exists for this subscription and date.']);
    }

    private function refresh(CustomerMealHold $hold, SubscriptionDateService $dates, DeliveryService $deliveries): void
    {
        $dates->recalculate($hold->subscription);
        foreach (['breakfast', 'lunch', 'dinner'] as $meal) if (! $hold->{$meal.'_required'}) Delivery::where('subscription_id', $hold->subscription_id)->whereDate('delivery_date', $hold->hold_date)->where('meal_type', $meal)->where('status', 'pending')->delete();
        $deliveries->generateForDate($hold->hold_date);
    }
}
