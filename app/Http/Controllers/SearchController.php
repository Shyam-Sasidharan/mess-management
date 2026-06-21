<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $term = trim((string) $request->q);
        $customers = strlen($term) >= 2 ? Customer::search($term)->limit(20)->get() : collect();
        $expenses = strlen($term) >= 2 ? Expense::with('category')->where(function ($q) use ($term) { $q->where('vendor_name', 'like', "%{$term}%")->orWhereHas('category', fn ($q) => $q->where('name', 'like', "%{$term}%")); })->limit(20)->get() : collect();
        return view('search', compact('term', 'customers', 'expenses'));
    }
}
