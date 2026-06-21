<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function data(array $filters = []): array
    {
        $date = Carbon::parse($filters['date'] ?? today());
        $month = (int) ($filters['month'] ?? $date->month);
        $year = (int) ($filters['year'] ?? $date->year);
        $customers = Customer::query()->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['place'] ?? null, fn ($q, $v) => $q->where('place', $v));
        $subscriptions = Subscription::query()->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))->when($filters['status'] ?? null, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('status', $v)))->when($filters['place'] ?? null, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)));
        $payments = Payment::query()->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->whereHas('subscription', fn ($q) => $q->where('payment_status', $v)))->when($filters['status'] ?? null, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('status', $v)))->when($filters['place'] ?? null, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)));
        $todayDeliveries = Delivery::whereDate('delivery_date', $date)->when($filters['meal_type'] ?? null, fn ($q, $v) => $q->where('meal_type', $v))->when($filters['place'] ?? null, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)));
        $monthlyRevenue = (clone $payments)->whereYear('payment_date', $year)->whereMonth('payment_date', $month)->sum('amount');
        $monthlyExpense = Expense::whereYear('expense_date', $year)->whereMonth('expense_date', $month)->sum('amount');
        $outstanding = (clone $subscriptions)->whereIn('payment_status', ['pending', 'partial'])->get()->sum('outstanding_amount');

        $monthly = collect(range(1, 12))->map(function ($m) use ($year, $payments, $filters) {
            $revenue = (float) (clone $payments)->whereYear('payment_date', $year)->whereMonth('payment_date', $m)->sum('amount');
            $expense = (float) Expense::whereYear('expense_date', $year)->whereMonth('expense_date', $m)->sum('amount');
            $customerGrowth = Customer::when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['place'] ?? null, fn ($q, $v) => $q->where('place', $v))->whereYear('created_at', $year)->whereMonth('created_at', $m)->count();
            return ['label' => Carbon::create($year, $m)->format('M'), 'revenue' => $revenue, 'expense' => $expense, 'profit' => $revenue - $expense, 'customers' => $customerGrowth];
        });

        $categoryExpenses = Expense::query()->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->whereYear('expense_date', $year)->whereMonth('expense_date', $month)
            ->select('expense_categories.name', 'expense_categories.color', DB::raw('SUM(expenses.amount) total'))
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.color')->orderByDesc('total')->get();
        $plans = (clone $subscriptions)->when($filters['meal_type'] ?? null, fn ($q, $v) => $q->where($v, true))->select('package_type', DB::raw('COUNT(*) total'))->groupBy('package_type')->get();
        $currentMonth = $monthly->firstWhere('label', Carbon::create($year, $month)->format('M'));
        $previousMonth = $monthly->get(max(0, $month - 2));
        $growth = fn (float $current, float $previous) => $previous == 0.0 ? ($current > 0 ? 100.0 : 0.0) : round((($current - $previous) / $previous) * 100, 1);

        return [
            'cards' => [
                'total_customers' => (clone $customers)->count(), 'active_customers' => (clone $customers)->where('status', 'active')->count(),
                'expired_customers' => (clone $customers)->where('status', 'expired')->count(),
                'new_customers' => (clone $customers)->whereYear('created_at', $year)->whereMonth('created_at', $month)->count(),
                'renewed_customers' => DB::table('subscription_renewals')->whereYear('renewed_on', $year)->whereMonth('renewed_on', $month)->distinct('customer_id')->count('customer_id'),
                'breakfast' => (clone $todayDeliveries)->where('meal_type', 'breakfast')->count(),
                'lunch' => (clone $todayDeliveries)->where('meal_type', 'lunch')->count(),
                'dinner' => (clone $todayDeliveries)->where('meal_type', 'dinner')->count(),
                'deliveries_today' => (clone $todayDeliveries)->count(),
                'pending_deliveries' => (clone $todayDeliveries)->where('status', 'pending')->count(),
                'today_revenue' => (clone $payments)->whereDate('payment_date', $date)->sum('amount'),
                'monthly_revenue' => $monthlyRevenue, 'monthly_expenses' => $monthlyExpense,
                'monthly_profit' => $monthlyRevenue - $monthlyExpense, 'outstanding' => $outstanding,
            ],
            'expiring' => Subscription::with('customer')->where('status', 'active')->whereBetween('end_date', [$date, $date->copy()->addDays(7)])->orderBy('end_date')->limit(8)->get(),
            'topExpenseCategory' => $categoryExpenses->first(),
            'popularPlan' => $plans->sortByDesc('total')->first(),
            'analytics' => [
                'best_revenue_month' => $monthly->sortByDesc('revenue')->first()['label'],
                'highest_expense_month' => $monthly->sortByDesc('expense')->first()['label'],
                'most_profitable_month' => $monthly->sortByDesc('profit')->first()['label'],
                'least_profitable_month' => $monthly->sortBy('profit')->first()['label'],
                'revenue_growth' => $growth((float) $currentMonth['revenue'], (float) $previousMonth['revenue']),
                'expense_growth' => $growth((float) $currentMonth['expense'], (float) $previousMonth['expense']),
            ],
            'monthly' => $monthly, 'categoryExpenses' => $categoryExpenses, 'plans' => $plans,
            'places' => Customer::whereNotNull('place')->distinct()->orderBy('place')->pluck('place'),
        ];
    }
}
