<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = ['customer_id', 'subscription_id', 'delivery_date', 'meal_type', 'status', 'delivered_at', 'notes', 'updated_by'];
    protected function casts(): array { return ['delivery_date' => 'date', 'delivered_at' => 'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
