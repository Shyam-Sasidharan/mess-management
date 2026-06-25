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
        $this->ensureSundayHolidays($start, $end, $request->user()?->id);
        $explicit = Holiday::whereBetween('holiday_date', [$start, $end])->orderBy('holiday_date')->get();
        $calendar = $explicit->mapWithKeys(fn ($holiday) => [$holiday->holiday_date->toDateString() => $holiday]);
        return view('holidays.index', ['holidays' => $explicit, 'calendar' => $calendar, 'start' => $start, 'month' => $month, 'year' => $year]);
    }

    public function store(Request $request, SubscriptionDateService $dates, DeliveryService $deliveries): RedirectResponse
    {
        $data = $this->validateData($request);
        $data = $this->normalizeCompensationType($data);
        $holiday = Holiday::create($data + ['created_by' => $request->user()->id, 'is_default_sunday' => Carbon::parse($data['holiday_date'])->isSunday()]);
        $this->refreshDate($holiday->holiday_date, $holiday->status === 'active', $dates, $deliveries);
        return back()->with('success', 'Holiday added and affected subscriptions recalculated.');
    }

    public function update(Request $request, Holiday $holiday, SubscriptionDateService $dates, DeliveryService $deliveries): RedirectResponse
    {
        $oldDate = $holiday->holiday_date->copy();
        $data = $this->validateData($request, $holiday);
        $data = $this->normalizeCompensationType($data);
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
            'compensation_type' => ['nullable', 'in:compensation,non_compensation'],
            'status' => ['required', 'in:active,inactive'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function normalizeCompensationType(array $data): array
    {
        $data['compensation_type'] ??= $data['type'] === 'weekly_holiday' ? 'non_compensation' : 'compensation';
        return $data;
    }

    private function ensureSundayHolidays(Carbon $start, Carbon $end, ?int $userId = null): void
    {
        for ($day = $start->copy(); $day <= $end; $day->addDay()) {
            if (! $day->isSunday()) continue;
            Holiday::firstOrCreate(
                ['holiday_date' => $day->toDateString()],
                [
                    'title' => 'Weekly Sunday',
                    'reason' => 'Automatic weekly off',
                    'type' => 'weekly_holiday',
                    'compensation_type' => 'non_compensation',
                    'is_default_sunday' => true,
                    'status' => 'active',
                    'created_by' => $userId,
                ]
            );
        }
    }

    private function refreshDate(Carbon $date, bool $isHoliday, SubscriptionDateService $dates, DeliveryService $deliveries): void
    {
        $dates->recalculateAffectedByDate($date);
        if ($isHoliday) Delivery::whereDate('delivery_date', $date)->where('status', 'pending')->delete();
        else $deliveries->generateForDate($date);
    }
}
