<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;
    protected $fillable = ['receipt_no', 'customer_id', 'subscription_id', 'payment_date', 'amount', 'method', 'notes', 'created_by'];
    protected function casts(): array { return ['payment_date' => 'date', 'amount' => 'decimal:2']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
