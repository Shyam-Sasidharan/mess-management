<?php

namespace App\Http\Controllers;

use App\Exports\ReportCollectionExport;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Holiday;
use App\Models\CustomerMealHold;
use App\Models\SubscriptionCompensation;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function index(Request $request, string $type = 'customers'): mixed
    {
        abort_unless(in_array($type, ['customers', 'deliveries', 'expenses', 'revenue', 'profit', 'payments', 'holidays', 'meal-holds', 'compensations', 'extensions', 'meal-not-required'], true), 404);
        [$rows, $columns, $summary] = $this->build($type, $request);
        if ($request->format === 'excel') return Excel::download(new ReportCollectionExport($rows, $columns), "{$type}-report.xlsx");
        if ($request->format === 'pdf' && class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.print', compact('type', 'rows', 'columns', 'summary'))->download("{$type}-report.pdf");
        }
        $customers = Customer::orderBy('name')->get(['id', 'name']);
        $places = Customer::whereNotNull('place')->distinct()->orderBy('place')->pluck('place');
        return view($request->format === 'print' ? 'reports.print' : 'reports.index', compact('type', 'rows', 'columns', 'summary', 'customers', 'places'));
    }

    private function build(string $type, Request $request): array
    {
        $from = Carbon::parse($request->from ?: now()->startOfMonth())->toDateString();
        $to = Carbon::parse($request->to ?: now()->endOfMonth())->toDateString();
        if ($type === 'customers') {
            $query = Customer::with('currentSubscription')->when($request->status, fn (Builder $q, $status) => $q->where('status', $status))->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
            $rows = $query->get()->map(fn ($r) => ['Code' => $r->customer_code, 'Customer' => $r->name, 'Mobile' => $r->primary_mobile, 'Place' => $r->place, 'Status' => ucfirst($r->status), 'Joined' => $r->created_at->format('d-m-Y')]);
            return [$rows, array_keys($rows->first() ?? ['Code'=>'','Customer'=>'','Mobile'=>'','Place'=>'','Status'=>'','Joined'=>'']), ['Records' => $rows->count()]];
        }
        if ($type === 'deliveries') {
            $query = Delivery::with('customer')->whereBetween('delivery_date', [$from, $to])->when($request->meal_type, fn ($q, $v) => $q->where('meal_type', $v))->when($request->status, fn ($q, $v) => $q->where('status', $v));
            $rows = $query->get()->map(fn ($r) => ['Date' => $r->delivery_date->format('d-m-Y'), 'Customer' => $r->customer->name, 'Meal' => ucfirst($r->meal_type), 'Area' => $r->customer->place, 'Status' => ucfirst($r->status)]);
            return [$rows, ['Date','Customer','Meal','Area','Status'], ['Total' => $rows->count(), 'Delivered' => $rows->where('Status', 'Delivered')->count()]];
        }
        if ($type === 'expenses') {
            $rows = Expense::with('category')->whereBetween('expense_date', [$from, $to])->when($request->category, fn ($q, $v) => $q->where('expense_category_id', $v))->get()->map(fn ($r) => ['Date' => $r->expense_date->format('d-m-Y'), 'Category' => $r->category->name, 'Vendor' => $r->vendor_name, 'Amount' => (float) $r->amount, 'Notes' => $r->notes]);
            return [$rows, ['Date','Category','Vendor','Amount','Notes'], ['Total expense' => $rows->sum('Amount')]];
        }
        if ($type === 'revenue') {
            $rows = Payment::with('customer')->whereBetween('payment_date', [$from, $to])->get()->map(fn ($r) => ['Date' => $r->payment_date->format('d-m-Y'), 'Receipt' => $r->receipt_no, 'Customer' => $r->customer->name, 'Method' => strtoupper($r->method), 'Amount' => (float) $r->amount]);
            return [$rows, ['Date','Receipt','Customer','Method','Amount'], ['Total revenue' => $rows->sum('Amount')]];
        }
        if ($type === 'payments') {
            $alertDays = (int) \App\Models\Setting::value('expiry_alert_days', 7);
            $query = Subscription::with('customer')->withSum('payments', 'amount')->withCount('payments')->withMax('payments', 'payment_date')
                ->when($request->customer, fn ($q, $v) => $q->where('customer_id', $v))
                ->when($request->place, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)))
                ->when($request->payment_status, fn ($q, $v) => $q->where('payment_status', $v))
                ->when($request->package_type, fn ($q, $v) => $q->where('package_type', $v))
                ->when($request->from, fn ($q, $v) => $q->whereDate('end_date', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->whereDate('end_date', '<=', $v))
                ->when($request->month, fn ($q, $v) => $q->whereMonth('end_date', $v))
                ->when($request->year, fn ($q, $v) => $q->whereYear('end_date', $v))
                ->when(in_array($request->payment_state, ['expired', 'overdue'], true), fn ($q) => $q->whereDate('end_date', '<', today())->whereIn('payment_status', ['pending', 'partial']))
                ->when($request->payment_state === 'expiring', fn ($q) => $q->whereBetween('end_date', [today(), today()->addDays($alertDays)])->whereIn('payment_status', ['pending', 'partial']));
            $service = app(\App\Services\PaymentService::class);
            $summaries = $query->get()->map(fn ($subscription) => $service->summary($subscription));
            $rows = $summaries->map(fn ($r) => ['Customer' => $r['customer'], 'Package' => $r['package_type'], 'Package Amount' => $r['package_amount'], 'Paid Amount' => $r['paid_amount'], 'Balance Amount' => $r['balance_amount'], 'Payment Status' => ucfirst($r['payment_status']), 'Last Payment' => $r['last_payment_date'] ?: '—', 'Transactions' => $r['transactions'], 'Expired Days' => $r['expired_days']]);
            return [$rows, ['Customer','Package','Package Amount','Paid Amount','Balance Amount','Payment Status','Last Payment','Transactions','Expired Days'], ['Subscriptions' => $rows->count(), 'Paid amount' => $summaries->sum('paid_amount'), 'Balance amount' => $summaries->sum('balance_amount')]];
        }
        if ($type === 'holidays') {
            $explicit = Holiday::whereBetween('holiday_date', [$from, $to])->get()->keyBy(fn ($holiday) => $holiday->holiday_date->toDateString());
            $rows = collect();
            for ($date = Carbon::parse($from); $date <= Carbon::parse($to); $date->addDay()) {
                $holiday = $explicit->get($date->toDateString());
                if ($holiday || $date->isSunday()) $rows->push(['Date' => $date->format('d-m-Y'), 'Holiday' => $holiday?->title ?? 'Default Sunday', 'Type' => str($holiday?->type ?? 'weekly_holiday')->replace('_', ' ')->title(), 'Reason' => $holiday?->reason, 'Status' => ucfirst($holiday?->status ?? 'active')]);
            }
            return [$rows, ['Date','Holiday','Type','Reason','Status'], ['Holiday days' => $rows->where('Status', 'Active')->count()]];
        }
        if ($type === 'meal-holds') {
            $query = CustomerMealHold::with(['customer', 'subscription'])->whereBetween('hold_date', [$from, $to])->when($request->customer, fn ($q, $v) => $q->where('customer_id', $v))->when($request->place, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)));
            $rows = $query->get()->map(fn ($r) => ['Date' => $r->hold_date->format('d-m-Y'), 'Customer' => $r->customer->name, 'Area' => $r->customer->place, 'Type' => $r->is_full_day_hold ? 'Full Day Hold' : 'Partial Meal Hold', 'Breakfast' => $r->subscription->breakfast ? ($r->breakfast_required ? 'Required' : 'Not Required') : 'Not Subscribed', 'Lunch' => $r->subscription->lunch ? ($r->lunch_required ? 'Required' : 'Not Required') : 'Not Subscribed', 'Dinner' => $r->subscription->dinner ? ($r->dinner_required ? 'Required' : 'Not Required') : 'Not Subscribed', 'Reason' => $r->reason]);
            return [$rows, ['Date','Customer','Area','Type','Breakfast','Lunch','Dinner','Reason'], ['Holds' => $rows->count(), 'Full-day holds' => $rows->where('Type', 'Full Day Hold')->count()]];
        }
        if ($type === 'compensations') {
            $query = SubscriptionCompensation::with(['customer', 'subscription'])->whereBetween('compensation_date', [$from, $to])->when($request->customer, fn ($q, $v) => $q->where('customer_id', $v))->when($request->compensation_type, fn ($q, $v) => $q->where('compensation_type', $v))->when($request->place, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)));
            $rows = $query->get()->map(fn ($r) => ['Date' => $r->compensation_date->format('d-m-Y'), 'Customer' => $r->customer->name, 'Area' => $r->customer->place, 'Subscription' => $r->subscription->subscription_no, 'Type' => str($r->compensation_type)->replace('_', ' ')->title(), 'Reason' => $r->reason, 'Days Added' => $r->days_added]);
            return [$rows, ['Date','Customer','Area','Subscription','Type','Reason','Days Added'], ['Compensation days' => $rows->sum('Days Added')]];
        }
        if ($type === 'extensions') {
            $query = Subscription::with('customer')->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->where(fn ($q) => $q->where('holiday_compensation_days', '>', 0)->orWhere('meal_hold_compensation_days', '>', 0))->when($request->customer, fn ($q, $v) => $q->where('customer_id', $v))->when($request->place, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)));
            $rows = $query->get()->map(fn ($r) => ['Customer' => $r->customer->name, 'Area' => $r->customer->place, 'Subscription' => $r->subscription_no, 'Start Date' => $r->start_date->format('d-m-Y'), 'Original End' => ($r->original_end_date ?? $r->end_date)->format('d-m-Y'), 'Holiday Days' => $r->holiday_compensation_days, 'Meal Hold Days' => $r->meal_hold_compensation_days, 'Extended End' => $r->end_date->format('d-m-Y'), 'Total Extension' => $r->holiday_compensation_days + $r->meal_hold_compensation_days]);
            return [$rows, ['Customer','Area','Subscription','Start Date','Original End','Holiday Days','Meal Hold Days','Extended End','Total Extension'], ['Subscriptions extended' => $rows->count(), 'Days added' => $rows->sum('Total Extension')]];
        }
        if ($type === 'meal-not-required') {
            $holds = CustomerMealHold::with(['customer', 'subscription'])->whereBetween('hold_date', [$from, $to])->when($request->customer, fn ($q, $v) => $q->where('customer_id', $v))->when($request->place, fn ($q, $v) => $q->whereHas('customer', fn ($q) => $q->where('place', $v)))->get();
            $rows = $holds->flatMap(function ($hold) use ($request) { return collect(['breakfast','lunch','dinner'])->filter(fn ($meal) => $hold->subscription->{$meal} && ! $hold->{$meal.'_required'} && (! $request->meal_type || $request->meal_type === $meal))->map(fn ($meal) => ['Date' => $hold->hold_date->format('d-m-Y'), 'Customer' => $hold->customer->name, 'Area' => $hold->customer->place, 'Meal' => ucfirst($meal), 'Full Day Hold' => $hold->is_full_day_hold ? 'Yes' : 'No', 'Reason' => $hold->reason]); });
            return [$rows, ['Date','Customer','Area','Meal','Full Day Hold','Reason'], ['Meals not required' => $rows->count()]];
        }
        $months = collect(); $cursor = Carbon::parse($from)->startOfMonth(); $end = Carbon::parse($to)->endOfMonth();
        while ($cursor <= $end) {
            $revenue = (float) Payment::whereYear('payment_date', $cursor->year)->whereMonth('payment_date', $cursor->month)->sum('amount');
            $expense = (float) Expense::whereYear('expense_date', $cursor->year)->whereMonth('expense_date', $cursor->month)->sum('amount');
            $months->push(['Month' => $cursor->format('M Y'), 'Revenue' => $revenue, 'Expense' => $expense, 'Profit' => $revenue - $expense]); $cursor->addMonth();
        }
        return [$months, ['Month','Revenue','Expense','Profit'], ['Revenue' => $months->sum('Revenue'), 'Expense' => $months->sum('Expense'), 'Profit' => $months->sum('Profit')]];
    }

}
