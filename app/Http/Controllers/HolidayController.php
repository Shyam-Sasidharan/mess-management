<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Holiday;
use App\Services\DeliveryService;
use App\Services\SubscriptionDateService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function index(Request $request): View
    {
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $start = Carbon::create($year, $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $explicit = Holiday::whereBetween('holiday_date', [$start, $end])->orderBy('holiday_date')->get();
        $calendar = $explicit->mapWithKeys(fn ($holiday) => [$holiday->holiday_date->toDateString() => $holiday]);
        for ($day = $start->copy(); $day <= $end; $day->addDay()) {
            if ($day->isSunday() && ! $calendar->has($day->toDateString())) $calendar->put($day->toDateString(), (object) ['title' => 'Weekly Sunday', 'type' => 'weekly_holiday', 'status' => 'active', 'is_default_sunday' => true]);
        }
        return view('holidays.index', ['holidays' => $explicit, 'calendar' => $calendar, 'start' => $start, 'month' => $month, 'year' => $year]);
    }

    public function store(Request $request, SubscriptionDateService $dates, DeliveryService $deliveries): RedirectResponse
    {
        $data = $this->validateData($request);
        $holiday = Holiday::create($data + ['created_by' => $request->user()->id, 'is_default_sunday' => Carbon::parse($data['holiday_date'])->isSunday()]);
        $this->refreshDate($holiday->holiday_date, $holiday->status === 'active', $dates, $deliveries);
        return back()->with('success', 'Holiday added and affected subscriptions recalculated.');
    }

    public function update(Request $request, Holiday $holiday, SubscriptionDateService $dates, DeliveryService $deliveries): RedirectResponse
    {
        $oldDate = $holiday->holiday_date->copy();
        $data = $this->validateData($request, $holiday);
        $holiday->update($data + ['is_default_sunday' => Carbon::parse($data['holiday_date'])->isSunday()]);
        $this->refreshDate($oldDate, false, $dates, $deliveries);
        $this->refreshDate($holiday->holiday_date, $holiday->status === 'active', $dates, $deliveries);
        return back()->with('success', 'Holiday updated and subscriptions recalculated.');
    }

    public function destroy(Holiday $holiday, SubscriptionDateService $dates, DeliveryService $deliveries): RedirectResponse
    {
        $date = $holiday->holiday_date->copy();
        $holiday->delete();
        $this->refreshDate($date, $date->isSunday(), $dates, $deliveries);
        return back()->with('success', 'Holiday removed and subscriptions recalculated.');
    }

    private function validateData(Request $request, ?Holiday $holiday = null): array
    {
        return $request->validate([
            'holiday_date' => ['required', 'date', Rule::unique('holidays')->ignore($holiday)],
            'title' => ['required', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'in:weekly_holiday,festival,emergency,custom'],
            'status' => ['required', 'in:active,inactive'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function refreshDate(Carbon $date, bool $isHoliday, SubscriptionDateService $dates, DeliveryService $deliveries): void
    {
        $dates->recalculateAffectedByDate($date);
        if ($isHoliday) Delivery::whereDate('delivery_date', $date)->where('status', 'pending')->delete();
        else $deliveries->generateForDate($date);
    }
}
