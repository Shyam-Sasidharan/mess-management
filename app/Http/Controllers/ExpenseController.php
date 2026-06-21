<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $expenses = Expense::with(['category', 'creator'])
            ->when($request->category, fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($request->from, fn ($q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($request->to, fn ($q, $to) => $q->whereDate('expense_date', '<=', $to))
            ->when($request->search, fn ($q, $term) => $q->where('vendor_name', 'like', "%{$term}%"))
            ->latest('expense_date')->paginate(20)->withQueryString();
        return view('expenses.index', ['expenses' => $expenses, 'categories' => ExpenseCategory::orderBy('name')->get()]);
    }
    public function create(): View { return view('expenses.create', ['categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get()]); }
    public function store(ExpenseRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('bill') + ['created_by' => $request->user()->id];
        if ($request->hasFile('bill')) { $data['bill_path'] = $request->file('bill')->store('expense-bills', 'local'); $data['bill_original_name'] = $request->file('bill')->getClientOriginalName(); }
        Expense::create($data);
        return redirect()->route('expenses.index')->with('success', 'Expense added.');
    }
    public function edit(Expense $expense): View { return view('expenses.edit', ['expense' => $expense, 'categories' => ExpenseCategory::where('is_active', true)->orWhere('id', $expense->expense_category_id)->orderBy('name')->get()]); }
    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $data = $request->safe()->except('bill');
        if ($request->hasFile('bill')) { if ($expense->bill_path) Storage::disk('local')->delete($expense->bill_path); $data['bill_path'] = $request->file('bill')->store('expense-bills', 'local'); $data['bill_original_name'] = $request->file('bill')->getClientOriginalName(); }
        $expense->update($data);
        return redirect()->route('expenses.index')->with('success', 'Expense updated.');
    }
    public function destroy(Expense $expense): RedirectResponse { $expense->delete(); return back()->with('success', 'Expense archived.'); }
    public function bill(Expense $expense) { abort_unless($expense->bill_path && Storage::disk('local')->exists($expense->bill_path), 404); return Storage::disk('local')->download($expense->bill_path, $expense->bill_original_name); }
}
