<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;
    protected $fillable = ['expense_date', 'expense_category_id', 'amount', 'vendor_name', 'notes', 'bill_path', 'bill_original_name', 'created_by'];
    protected function casts(): array { return ['expense_date' => 'date', 'amount' => 'decimal:2']; }
    public function category(): BelongsTo { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
