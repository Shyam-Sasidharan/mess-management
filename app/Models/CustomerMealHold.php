<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMealHold extends Model
{
    protected $fillable = ['customer_id', 'subscription_id', 'hold_date', 'breakfast_required', 'lunch_required', 'dinner_required', 'is_full_day_hold', 'reason', 'notes', 'created_by'];
    protected function casts(): array { return ['hold_date' => 'date', 'breakfast_required' => 'boolean', 'lunch_required' => 'boolean', 'dinner_required' => 'boolean', 'is_full_day_hold' => 'boolean']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
