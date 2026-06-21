<?php

namespace App\Http\Controllers;

use App\Exports\ReportCollectionExport;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Expense;
use App\Models\Payment;
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
        abort_unless(in_array($type, ['customers', 'deliveries', 'expenses', 'revenue', 'profit'], true), 404);
        [$rows, $columns, $summary] = $this->build($type, $request);
        if ($request->format === 'excel') return Excel::download(new ReportCollectionExport($rows, $columns), "{$type}-report.xlsx");
        if ($request->format === 'pdf' && class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.print', compact('type', 'rows', 'columns', 'summary'))->download("{$type}-report.pdf");
        }
        return view($request->format === 'print' ? 'reports.print' : 'reports.index', compact('type', 'rows', 'columns', 'summary'));
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
        $months = collect(); $cursor = Carbon::parse($from)->startOfMonth(); $end = Carbon::parse($to)->endOfMonth();
        while ($cursor <= $end) {
            $revenue = (float) Payment::whereYear('payment_date', $cursor->year)->whereMonth('payment_date', $cursor->month)->sum('amount');
            $expense = (float) Expense::whereYear('expense_date', $cursor->year)->whereMonth('expense_date', $cursor->month)->sum('amount');
            $months->push(['Month' => $cursor->format('M Y'), 'Revenue' => $revenue, 'Expense' => $expense, 'Profit' => $revenue - $expense]); $cursor->addMonth();
        }
        return [$months, ['Month','Revenue','Expense','Profit'], ['Revenue' => $months->sum('Revenue'), 'Expense' => $months->sum('Expense'), 'Profit' => $months->sum('Profit')]];
    }

}
