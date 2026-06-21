<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse { ExpenseCategory::create($request->validate(['name' => ['required', 'string', 'max:255', 'unique:expense_categories,name'], 'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/']])); return back()->with('success', 'Category added.'); }
    public function update(Request $request, ExpenseCategory $category): RedirectResponse { $category->update($request->validate(['name' => ['required', 'string', 'max:255', 'unique:expense_categories,name,'.$category->id], 'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'is_active' => ['nullable', 'boolean']])); return back()->with('success', 'Category updated.'); }
}
