<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMealHold;
use App\Models\Delivery;
use App\Models\Expense;
use App\Models\Holiday;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private SubscriptionDateService $subscriptionDates) {}

    public function data(array $filters = []): array
    {
        $date = Carbon::parse($filters['date'] ?? today())->startOfDay();
        $month = (int) ($filters['month'] ?? $date->month);
        $year = (int) ($filters['year'] ?? $date->year);
        $customers = $this->customerQuery($filters);
        $subscriptions = $this->subscriptionQuery($filters);
        $payments = $this->paymentQuery($filters)
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('payment_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('payment_date', '<=', $to));
        $expenses = Expense::query()->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('expense_date', '>=', $from))->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('expense_date', '<=', $to));

        $isHoliday = $this->subscriptionDates->isHoliday($date);
        $deliveryQuery = Delivery::whereDate('delivery_date', $date)
            ->when($filters['meal_type'] ?? null, fn ($query, $meal) => $query->where('meal_type', $meal))
            ->when($filters['place'] ?? null, fn ($query, $place) => $query->whereHas('customer', fn ($q) => $q->where('place', $place)));
        $deliveryRows = $isHoliday ? collect() : (clone $deliveryQuery)->select('meal_type', 'status', DB::raw('COUNT(*) total'))->groupBy('meal_type', 'status')->get();
        $deliveryStats = collect(['breakfast', 'lunch', 'dinner'])->mapWithKeys(function ($meal) use ($deliveryRows) {
            $rows = $deliveryRows->where('meal_type', $meal)->pluck('total', 'status');
            return [$meal => ['total' => (int) $rows->sum(), 'pending' => (int) ($rows['pending'] ?? 0), 'delivered' => (int) ($rows['delivered'] ?? 0), 'exceptions' => (int) (($rows['missed'] ?? 0) + ($rows['skipped'] ?? 0))]];
        })->all();

        $monthlyRevenue = (float) (clone $payments)->whereYear('payment_date', $year)->whereMonth('payment_date', $month)->sum('amount');
        $monthlyExpense = (float) (clone $expenses)->whereYear('expense_date', $year)->whereMonth('expense_date', $month)->sum('amount');
        $monthStart = Carbon::create($year, $month)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $monthlyHolidayCounts = $this->holidayCompensationCounts($monthStart, $monthEnd);
        $previous = Carbon::create($year, $month)->subMonthNoOverflow();
        $previousRevenue = (float) (clone $payments)->whereYear('payment_date', $previous->year)->whereMonth('payment_date', $previous->month)->sum('amount');
        $previousExpense = (float) (clone $expenses)->whereYear('expense_date', $previous->year)->whereMonth('expense_date', $previous->month)->sum('amount');
        $outstandingSubscriptions = (clone $subscriptions)->whereIn('payment_status', ['pending', 'partial'])->withSum('payments', 'amount')->get(['id', 'amount']);
        $outstanding = $outstandingSubscriptions->sum(fn ($subscription) => max(0, (float) $subscription->amount - (float) $subscription->payments_sum_amount));
        $paymentStatusCounts = (clone $subscriptions)->select('payment_status', DB::raw('COUNT(*) total'))->groupBy('payment_status')->pluck('total', 'payment_status');
        $expiredUnpaidCount = (clone $subscriptions)->where('status', 'expired')->whereIn('payment_status', ['pending', 'partial'])->count();
        $expiringUnpaidCount = (clone $subscriptions)->where('status', 'active')->whereIn('payment_status', ['pending', 'partial'])->whereBetween('end_date', [$date, $date->copy()->addDays((int) \App\Models\Setting::value('expiry_alert_days', 7))])->count();

        $monthly = $this->monthlySeries($payments, $expenses, $year);
        $categoryExpenses = (clone $expenses)->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->whereYear('expense_date', $year)->whereMonth('expense_date', $month)
            ->select('expense_categories.name', 'expense_categories.color', DB::raw('SUM(expenses.amount) total'))
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.color')->orderByDesc('total')->get();
        $plans = (clone $subscriptions)->when($filters['meal_type'] ?? null, fn ($query, $meal) => $query->where($meal, true))
            ->select('package_type', DB::raw('COUNT(*) total'))->groupBy('package_type')->get();

        $expiryQuery = Subscription::with('customer')->where('status', 'active')
            ->when($filters['place'] ?? null, fn ($query, $place) => $query->whereHas('customer', fn ($q) => $q->where('place', $place)));
        $expiringToday = (clone $expiryQuery)->whereDate('end_date', $date)->orderBy('end_date')->limit(5)->get();
        $expiring = (clone $expiryQuery)->whereBetween('end_date', [$date->copy()->addDay(), $date->copy()->addDays(7)])->orderBy('end_date')->limit(6)->get();
        $pendingCustomers = (clone $subscriptions)->with('customer')->withSum('payments', 'amount')->whereIn('payment_status', ['pending', 'partial'])->orderBy('end_date')->limit(6)->get()
            ->each(fn ($subscription) => $subscription->setAttribute('computed_outstanding', max(0, (float) $subscription->amount - (float) $subscription->payments_sum_amount)));
        $mealHoldCount = CustomerMealHold::whereDate('hold_date', $date)->when($filters['place'] ?? null, fn ($query, $place) => $query->whereHas('customer', fn ($q) => $q->where('place', $place)))->count();

        $currentHoliday = Holiday::active()->whereDate('holiday_date', $date)->first();
        $nextExplicitHoliday = Holiday::active()->whereDate('holiday_date', '>', $date)->orderBy('holiday_date')->first();
        $nextSunday = $date->copy()->next(Carbon::SUNDAY);
        $nextHolidayDate = $nextExplicitHoliday && $nextExplicitHoliday->holiday_date->lt($nextSunday) ? $nextExplicitHoliday->holiday_date : $nextSunday;
        $nextHolidayTitle = $nextExplicitHoliday && $nextExplicitHoliday->holiday_date->isSameDay($nextHolidayDate) ? $nextExplicitHoliday->title : 'Weekly Sunday';

        $profit = $monthlyRevenue - $monthlyExpense;
        $growth = fn (float $current, float $old) => $old == 0.0 ? ($current > 0 ? 100.0 : 0.0) : round((($current - $old) / $old) * 100, 1);
        $activeCustomers = (clone $customers)->where('status', 'active')->count();
        $deliveryTotal = array_sum(array_column($deliveryStats, 'total'));

        return [
            'filterDate' => $date,
            'cards' => [
                'active_customers' => $activeCustomers,
                'deliveries_today' => $deliveryTotal,
                'monthly_revenue' => $monthlyRevenue,
                'monthly_expenses' => $monthlyExpense,
                'monthly_profit' => $profit,
                'outstanding' => $outstanding,
                'pending_payment_count' => $outstandingSubscriptions->count(),
                'compensation_holidays' => (int) ($monthlyHolidayCounts['compensation'] ?? 0),
                'non_compensation_holidays' => (int) ($monthlyHolidayCounts['non_compensation'] ?? 0),
            ],
            'deliveryStats' => $deliveryStats,
            'finance' => [
                'today_collection' => (float) (clone $payments)->whereDate('payment_date', $date)->sum('amount'),
                'monthly_revenue' => $monthlyRevenue, 'monthly_expense' => $monthlyExpense, 'monthly_profit' => $profit,
                'outstanding' => $outstanding, 'revenue_growth' => $growth($monthlyRevenue, $previousRevenue),
                'expense_growth' => $growth($monthlyExpense, $previousExpense), 'state' => $profit > 0 ? 'profit' : ($profit < 0 ? 'loss' : 'neutral'),
            ],
            'holiday' => ['is_holiday' => $isHoliday, 'title' => $currentHoliday?->title ?? ($date->isSunday() ? 'Weekly Sunday' : null), 'next_date' => $nextHolidayDate, 'next_title' => $nextHolidayTitle],
            'alerts' => ['expiring_today' => $expiringToday->count(), 'expiring_week' => $expiring->count(), 'pending_payments' => $outstandingSubscriptions->count(), 'partial_payments' => (int) ($paymentStatusCounts['partial'] ?? 0), 'fully_paid' => (int) ($paymentStatusCounts['paid'] ?? 0), 'expired_unpaid' => $expiredUnpaidCount, 'expiring_unpaid' => $expiringUnpaidCount, 'meal_holds' => $mealHoldCount, 'new_customers' => (clone $customers)->whereYear('created_at', $year)->whereMonth('created_at', $month)->count()],
            'expiringToday' => $expiringToday, 'expiring' => $expiring, 'pendingCustomers' => $pendingCustomers,
            'topExpenseCategory' => $categoryExpenses->first(), 'monthly' => $monthly,
            'categoryExpenses' => $categoryExpenses, 'plans' => $plans,
            'places' => Customer::whereNotNull('place')->distinct()->orderBy('place')->pluck('place'),
        ];
    }

    private function customerQuery(array $filters): Builder
    {
        return Customer::query()->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->when($filters['place'] ?? null, fn ($query, $place) => $query->where('place', $place));
    }

    private function subscriptionQuery(array $filters): Builder
    {
        return Subscription::query()->when($filters['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereHas('customer', fn ($q) => $q->where('status', $status)))
            ->when($filters['place'] ?? null, fn ($query, $place) => $query->whereHas('customer', fn ($q) => $q->where('place', $place)));
    }

    private function paymentQuery(array $filters): Builder
    {
        return Payment::query()->when($filters['payment_status'] ?? null, fn ($query, $status) => $query->whereHas('subscription', fn ($q) => $q->where('payment_status', $status)))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereHas('customer', fn ($q) => $q->where('status', $status)))
            ->when($filters['place'] ?? null, fn ($query, $place) => $query->whereHas('customer', fn ($q) => $q->where('place', $place)));
    }

    private function monthlySeries(Builder $payments, Builder $expenseQuery, int $year): \Illuminate\Support\Collection
    {
        $driver = DB::connection()->getDriverName();
        $monthExpression = $driver === 'sqlite' ? "CAST(strftime('%m', payment_date) AS INTEGER)" : 'MONTH(payment_date)';
        $expenseMonthExpression = $driver === 'sqlite' ? "CAST(strftime('%m', expense_date) AS INTEGER)" : 'MONTH(expense_date)';
        $revenues = (clone $payments)->whereYear('payment_date', $year)->selectRaw("{$monthExpression} month, SUM(amount) total")->groupByRaw($monthExpression)->pluck('total', 'month');
        $expenses = (clone $expenseQuery)->whereYear('expense_date', $year)->selectRaw("{$expenseMonthExpression} month, SUM(expenses.amount) total")->groupByRaw($expenseMonthExpression)->pluck('total', 'month');
        return collect(range(1, 12))->map(function ($month) use ($year, $revenues, $expenses) {
            $revenue = (float) ($revenues[$month] ?? 0); $expense = (float) ($expenses[$month] ?? 0);
            return ['label' => Carbon::create($year, $month)->format('M'), 'revenue' => $revenue, 'expense' => $expense, 'profit' => $revenue - $expense];
        });
    }

    private function holidayCompensationCounts(Carbon $start, Carbon $end): array
    {
        $explicit = Holiday::active()->whereBetween('holiday_date', [$start, $end])->get()->keyBy(fn ($holiday) => $holiday->holiday_date->toDateString());
        $counts = ['compensation' => 0, 'non_compensation' => 0];
        for ($date = $start->copy(); $date <= $end; $date->addDay()) {
            $holiday = $explicit->get($date->toDateString());
            if ($holiday) $counts[$holiday->compensation_type]++;
            elseif ($date->isSunday()) $counts['non_compensation']++;
        }
        return $counts;
    }
}
