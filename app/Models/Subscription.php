<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id', 'subscription_no', 'start_date', 'end_date', 'original_end_date', 'subscription_days',
        'holiday_compensation_days', 'meal_hold_compensation_days',
        'breakfast', 'lunch', 'dinner', 'meal_count', 'package_type', 'amount',
        'payment_status', 'status', 'paused_at', 'resumed_at', 'created_by',
    ];

    protected $appends = ['paid_amount', 'outstanding_amount', 'meal_names'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'original_end_date' => 'date', 'breakfast' => 'boolean',
            'lunch' => 'boolean', 'dinner' => 'boolean', 'amount' => 'decimal:2',
            'paused_at' => 'datetime', 'resumed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function deliveries(): HasMany { return $this->hasMany(Delivery::class); }
    public function mealHolds(): HasMany { return $this->hasMany(CustomerMealHold::class); }
    public function compensations(): HasMany { return $this->hasMany(SubscriptionCompensation::class); }
    public function renewalRecord(): HasOne { return $this->hasOne(SubscriptionRenewal::class, 'new_subscription_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function getPaidAmountAttribute(): float { return (float) $this->payments()->sum('amount'); }
    public function getOutstandingAmountAttribute(): float { return max(0, (float) $this->amount - $this->paid_amount); }
    public function getMealNamesAttribute(): string
    {
        return collect(['breakfast', 'lunch', 'dinner'])->filter(fn ($meal) => $this->{$meal})->map(fn ($meal) => ucfirst($meal))->join(', ');
    }
}
